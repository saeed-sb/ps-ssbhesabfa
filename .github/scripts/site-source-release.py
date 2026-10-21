#!/usr/bin/env python3
"""Standard-library release tool. No installation or production API calls."""
import argparse
import hashlib
import json
import os
import re
import stat
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from pathlib import Path, PurePosixPath

EXCLUDED_DIRS = {
    '.git', '.github', '_dev', 'tests', 'tools', 'docs', 'fixtures',
    'templates_c', 'cache', '.cache', 'logs', 'uploads', 'downloads',
    'dist', 'build', 'node_modules', 'archives', '__pycache__',
}
EXCLUDED_SUFFIXES = {
    '.zip', '.tar', '.gz', '.bz2', '.xz', '.7z', '.rar', '.exe', '.msi',
    '.phar', '.log', '.sqlite', '.sqlite3', '.db', '.pem', '.key',
    '.p12', '.pfx', '.pyc', '.bak', '.orig', '.patch',
}
EXCLUDED_NAMES = {'auth.json', '.npmrc', '.pypirc', '.DS_Store', 'secrets.json'}
# Reviewed standalone, network-free tests only. Never guess installation scripts.
LOCAL_TESTS = {
    'ssbhesabfa': [["php","tests/freight_margin_upgrade.php"],["php","tests/queue_repository_guards.php"],["php","tests/log_level_mapping.php"],["php","tests/invoice_item_pagination.php"],["php","tests/freight-replacement-sync.php"],["php","tests/invoice_carrier_sync.php"],["php","tests/invoice_carrier_upgrade.php"],["php","tests/manual_payment_reconciliation.php"],["php","tests/module_security_bootstrap.php"],["php","tests/paymenthub_compatibility.php"],["php","tests/queue_webhook_recovery.php"],["php","tests/security_boundaries.php"]],
    'ssbeditproduct': [
        ['php', 'tests/HomeCategoryCheckboxStateTest.php'],
        ['php', 'tests/PriceFilterSanitizationTest.php'],
    ],
}
LOCAL_TESTS['ssbhesabfa'].append(['php', 'tests/payment_fee_expense.php'])

DOWNLOAD_HOSTS = {
    'release-assets.githubusercontent.com', 'objects.githubusercontent.com',
    'github-releases.githubusercontent.com',
}


def fail(message):
    raise RuntimeError(message)


def run(arguments, cwd=None):
    result = subprocess.run(arguments, cwd=cwd, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, text=True)
    if result.returncode:
        # Do not echo arbitrary process arguments or environment credentials.
        fail('Command failed: ' + arguments[0] + '; ' +
             (result.stdout + result.stderr)[-3000:])
    return result.stdout


def digest(path):
    value = hashlib.sha256()
    with path.open('rb') as source:
        for block in iter(lambda: source.read(1024 * 1024), b''):
            value.update(block)
    return value.hexdigest()


def identity(root):
    module = os.environ.get('MODULE_NAME', '')
    version = os.environ.get('RELEASE_VERSION', '')
    repository = os.environ.get('EXPECTED_REPOSITORY', '')
    if not re.fullmatch(r'[a-z][a-z0-9_]{1,63}', module):
        fail('Invalid fixed module name')
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        fail('Invalid fixed release version')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
        fail('Invalid fixed repository')
    if os.environ.get('GITHUB_REPOSITORY') != repository:
        fail('Workflow repository differs from its fixed release repository')
    if os.environ.get('GITHUB_REF') not in ('refs/heads/main', 'refs/heads/master'):
        fail('Release must run against main or master')
    sha = os.environ.get('GITHUB_SHA', '')
    if not re.fullmatch(r'[0-9a-f]{40}', sha):
        fail('Missing exact workflow commit')
    if run(['git', 'rev-parse', 'HEAD'], root).strip() != sha:
        fail('Checkout differs from the exact workflow commit')
    if run(['git', 'status', '--porcelain', '--untracked-files=no'], root).strip():
        fail('Tracked source was modified after checkout')
    return {'module': module, 'version': version, 'repository': repository,
            'commit': sha, 'tag': 'v' + version}


def tracked_files(root):
    raw = subprocess.check_output(['git', 'ls-files', '--stage', '-z'], cwd=root)
    entries = []
    for item in raw.split(b'\0'):
        if not item:
            continue
        metadata, raw_path = item.split(b'\t', 1)
        mode, object_id, stage = metadata.decode('ascii').split(' ')
        path = raw_path.decode('utf-8', 'strict')
        pure = PurePosixPath(path)
        if (pure.is_absolute() or not pure.parts or '..' in pure.parts
                or '\\' in path or '\x00' in path or ':' in pure.parts[0]):
            fail('Unsafe tracked file path')
        if stage != '0':
            fail('Unmerged tracked source is not releasable')
        entries.append((path, mode))
    return sorted(entries)


def source_version(root, info):
    path = root / (info['module'] + '.php')
    if not path.is_file():
        fail('Module entry point is missing')
    text = path.read_text(encoding='utf-8')
    versions = re.findall(
        r"(?:const\s+(?:MODULE_VERSION|VERSION)|\$this->version)\s*=\s*(['\"])(\d+\.\d+\.\d+)\1",
        text,
    )
    if not versions or {value for quote, value in versions} != {info['version']}:
        fail('Module source version differs from fixed release version')
    import xml.etree.ElementTree as ET
    manifests = []
    for name in ('config.xml', 'config_fa.xml'):
        path = root / name
        if not path.is_file():
            if name == 'config.xml':
                fail('Required module XML manifest is missing')
            continue
        parsed = ET.fromstring(path.read_bytes())
        if (parsed.findtext('name') or '').strip() != info['module']:
            fail('XML module name differs from fixed module')
        if (parsed.findtext('version') or '').strip() != info['version']:
            fail('XML version differs from fixed release version')
        manifests.append(name)
    return manifests


def allowed_path(path, mode):
    parts = PurePosixPath(path).parts
    if mode not in ('100644', '100755'):
        return False
    if any(part in EXCLUDED_DIRS for part in parts[:-1]):
        return False
    name = parts[-1]
    if name in EXCLUDED_NAMES or name.startswith('.env'):
        return False
    if any(suffix.lower() in EXCLUDED_SUFFIXES for suffix in PurePosixPath(name).suffixes):
        return False
    return True


def read_regular_file(root, path):
    target = root / path
    try:
        target.resolve().relative_to(root.resolve())
    except ValueError:
        fail('Tracked file resolves outside the checkout')
    if not stat.S_ISREG(target.lstat().st_mode) or target.is_symlink():
        fail('Tracked regular file was replaced with another file type')
    # Never follow a replaced parent directory symlink.
    for parent in target.parents:
        if parent == root:
            break
        if parent.is_symlink():
            fail('Tracked file has a symlinked parent directory')
    return target


def validate(root):
    info = identity(root)
    manifests = source_version(root, info)
    php = os.environ.get('RELEASE_PHP', '')
    if php not in ('8.1', '8.2'):
        fail('Unexpected PHP matrix entry')
    actual = run(['php', '-r', 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;'], root)
    if actual != php:
        fail('Configured PHP runtime differs from matrix')
    files = tracked_files(root)
    linted = 0
    for path, mode in files:
        if mode in ('100644', '100755') and path.lower().endswith('.php'):
            read_regular_file(root, path)
            run(['php', '-l', path], root)
            linted += 1
    if not linted:
        fail('No tracked PHP sources were validated')
    tests = []
    for command in LOCAL_TESTS.get(info['module'], []):
        if not (root / command[1]).is_file():
            fail('An approved local test is missing')
        output = run(command, root)
        tests.append({'command': command, 'passed': True, 'output': output[-3000:]})
    # Detect tests that accidentally changed repository source or version metadata.
    identity(root)
    report = dict(info, php=php, passed=True, linted_php_files=linted,
                  xml_manifests=manifests, local_tests=tests,
                  limitations=[
                      'No PrestaShop installation or upgrade was executed.',
                      'No production settings, customer data, SMS, payments, shipping or provider API was exercised.',
                      'Only the explicitly listed standalone tests were run; all other functional behavior is unverified by this workflow.',
                  ])
    destination = root / 'dist' / ('validation-php' + php + '.json')
    destination.parent.mkdir(exist_ok=True)
    destination.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    print('Validated PHP ' + php + ': ' + str(linted) + ' source files; ' + str(len(tests)) + ' local test commands')
    return report


def build(root):
    info = identity(root)
    manifests = source_version(root, info)
    reports = []
    for php in ('8.1', '8.2'):
        path = root / 'dist' / 'validation-input' / ('validation-php' + php + '.json')
        if not path.is_file():
            fail('Both PHP validation reports are required')
        report = json.loads(path.read_text(encoding='utf-8'))
        if any(report.get(key) != value for key, value in info.items()):
            fail('Validation report belongs to a different release or source commit')
        if report.get('php') != php or report.get('passed') is not True or report.get('linted_php_files', 0) <= 0:
            fail('PHP validation report did not pass')
        reports.append(report)
    all_files = tracked_files(root)
    selected = [(path, mode) for path, mode in all_files if allowed_path(path, mode)]
    names = {path for path, mode in selected}
    if info['module'] + '.php' not in names or 'config.xml' not in names:
        fail('Package is missing module entry point or XML manifest')
    output = root / 'dist'
    output.mkdir(exist_ok=True)
    archive = output / (info['module'] + '-' + info['version'] + '.zip')
    manifest = []
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as target:
        for path, mode in selected:
            source = read_regular_file(root, path)
            data = source.read_bytes()
            entry = zipfile.ZipInfo(info['module'] + '/' + path, date_time=(2020, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | (0o755 if mode == '100755' else 0o644)) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            target.writestr(entry, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
            manifest.append({'path': path, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    with zipfile.ZipFile(archive) as package:
        if package.testzip() is not None:
            fail('ZIP integrity check failed')
        entries = package.infolist()
        if not entries or not any(item.file_size > 0 for item in entries):
            fail('ZIP is empty')
        if len({item.filename for item in entries}) != len(entries):
            fail('ZIP contains duplicate paths')
        expected = {info['module'] + '/' + path for path, mode in selected}
        if {item.filename for item in entries} != expected:
            fail('ZIP entries differ from the tracked package allowlist')
        for item in entries:
            pure = PurePosixPath(item.filename)
            if pure.is_absolute() or '..' in pure.parts or pure.parts[0] != info['module']:
                fail('Unsafe ZIP extraction path')
            if stat.S_ISLNK(item.external_attr >> 16):
                fail('ZIP contains a symlink')
    report = dict(info, passed=True, package=archive.name, package_sha256=digest(archive),
                  package_bytes=archive.stat().st_size, packaged_files=len(selected),
                  excluded_tracked_files=len(all_files)-len(selected), xml_manifests=manifests,
                  php_validation=reports, file_manifest=manifest,
                  limitations=reports[0]['limitations'],
                  publication_verification='The workflow separately downloads and verifies all draft release assets before publication.')
    validation = output / 'validation.json'
    validation.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    checksums = output / 'SHA256SUMS.txt'
    checksums.write_text(digest(archive) + '  ' + archive.name + '\n' +
                         digest(validation) + '  ' + validation.name + '\n', encoding='ascii')
    # The store's updater recognizes the exact ZIP.sha256 companion.
    (output / (archive.name + '.sha256')).write_text(digest(archive) + '  ' + archive.name + '\n', encoding='ascii')
    print('Built ' + archive.name + ': ' + str(len(selected)) + ' files; SHA256 ' + digest(archive))
    return report


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


class GitHub:
    def __init__(self, repository, token):
        if not token:
            fail('Missing workflow release token')
        self.repository = repository
        self.token = token
        self.opener = urllib.request.build_opener(NoRedirect())

    def api(self, method, suffix, value=None, allow_missing=False):
        path = '/repos/' + self.repository + suffix
        data = None if value is None else json.dumps(value).encode('utf-8')
        request = urllib.request.Request('https://api.github.com' + path, data=data, method=method,
            headers={'Authorization': 'Bearer ' + self.token, 'Accept': 'application/vnd.github+json',
                     'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'site-source-release',
                     'Content-Type': 'application/json'})
        try:
            with self.opener.open(request, timeout=60) as response:
                return json.loads(response.read().decode('utf-8'))
        except urllib.error.HTTPError as error:
            if allow_missing and error.code == 404:
                return None
            fail('GitHub API failed: ' + method + ' ' + suffix.split('?')[0] + ' (HTTP ' + str(error.code) + ')')
        except urllib.error.URLError:
            fail('GitHub API network failure')

    def ensure_unused(self, info):
        if self.api('GET', '/git/ref/tags/' + info['tag'], allow_missing=True) is not None:
            fail('Version tag already exists; it will never be moved or overwritten')
        for page in range(1, 101):
            releases = self.api('GET', '/releases?per_page=100&page=' + str(page))
            if not isinstance(releases, list):
                fail('Unexpected release listing response')
            if any(item.get('tag_name') == info['tag'] for item in releases):
                fail('A release or draft already uses this version; it will never be overwritten')
            if len(releases) < 100:
                return
        fail('Release listing exceeded safe pagination limit')

    def upload(self, release, path):
        endpoint = release.get('upload_url', '').split('{', 1)[0]
        parsed = urllib.parse.urlsplit(endpoint)
        if (parsed.scheme != 'https' or parsed.hostname != 'uploads.github.com'
                or parsed.path != '/repos/' + self.repository + '/releases/' + str(release['id']) + '/assets'
                or parsed.query or parsed.fragment or parsed.username or parsed.password
                or parsed.port not in (None, 443)):
            fail('Unexpected release upload endpoint')
        request = urllib.request.Request(endpoint + '?name=' + urllib.parse.quote(path.name, safe=''),
            data=path.read_bytes(), method='POST',
            headers={'Authorization': 'Bearer ' + self.token, 'Accept': 'application/vnd.github+json',
                     'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'site-source-release',
                     'Content-Type': 'application/octet-stream'})
        try:
            with self.opener.open(request, timeout=120) as response:
                asset = json.loads(response.read().decode('utf-8'))
        except urllib.error.HTTPError as error:
            fail('Release asset upload failed (HTTP ' + str(error.code) + ')')
        except urllib.error.URLError:
            fail('Release asset upload network failure; inspect the new draft before retrying')
        if asset.get('name') != path.name or asset.get('size') != path.stat().st_size:
            fail('Uploaded release asset metadata differs from local bytes')
        return asset

    def verify_asset(self, asset, path):
        url = 'https://api.github.com/repos/' + self.repository + '/releases/assets/' + str(asset['id'])
        headers = {'Authorization': 'Bearer ' + self.token, 'Accept': 'application/octet-stream',
                   'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'site-source-release'}
        for redirect in range(6):
            try:
                response = self.opener.open(urllib.request.Request(url, headers=headers), timeout=120)
                break
            except urllib.error.HTTPError as error:
                if error.code not in (301, 302, 303, 307, 308):
                    fail('Release asset verification download failed (HTTP ' + str(error.code) + ')')
                location = error.headers.get('Location', '')
                parsed = urllib.parse.urlsplit(location)
                if (parsed.scheme != 'https' or parsed.hostname not in DOWNLOAD_HOSTS
                        or parsed.username or parsed.password or parsed.port not in (None, 443)):
                    fail('Release asset redirect did not target an approved GitHub asset host')
                url = location
                # Never forward the workflow token to a signed object download.
                headers = {'User-Agent': 'site-source-release'}
            except urllib.error.URLError:
                fail('Release asset verification network failure')
        else:
            fail('Too many release asset redirects')
        checksum = hashlib.sha256()
        received = 0
        expected_size = path.stat().st_size
        with response:
            for block in iter(lambda: response.read(1024 * 1024), b''):
                received += len(block)
                if received > expected_size:
                    fail('Downloaded release asset exceeds expected size')
                checksum.update(block)
        if received != expected_size or checksum.hexdigest() != digest(path):
            fail('Downloaded release asset hash or size differs from built asset')
        return {'name': path.name, 'bytes': received, 'sha256': checksum.hexdigest()}

    def verify_tag_commit(self, info):
        reference = self.api('GET', '/git/ref/tags/' + info['tag'])
        obj = reference.get('object', {})
        for depth in range(5):
            if obj.get('type') == 'commit':
                if obj.get('sha') != info['commit']:
                    fail('Published tag points to a different source commit')
                return
            if obj.get('type') != 'tag' or not re.fullmatch(r'[0-9a-f]{40}', obj.get('sha', '')):
                fail('Unexpected published tag object')
            obj = self.api('GET', '/git/tags/' + obj['sha']).get('object', {})
        fail('Published tag nesting exceeded safe limit')


def preflight(root):
    info = identity(root)
    source_version(root, info)
    client = GitHub(info['repository'], os.environ.get('GH_TOKEN', ''))
    client.ensure_unused(info)
    print('Version is unused: ' + info['tag'] + '; source commit ' + info['commit'])


def publish(root):
    info = identity(root)
    source_version(root, info)
    client = GitHub(info['repository'], os.environ.get('GH_TOKEN', ''))
    # Recheck immediately before the first mutation. Never resume another draft.
    client.ensure_unused(info)
    directory = root / 'dist'
    archive = directory / (info['module'] + '-' + info['version'] + '.zip')
    validation = directory / 'validation.json'
    checksums = directory / 'SHA256SUMS.txt'
    package_checksum = directory / (archive.name + '.sha256')
    report = json.loads(validation.read_text(encoding='utf-8'))
    if (any(report.get(key) != value for key, value in info.items()) or report.get('passed') is not True
            or report.get('package_sha256') != digest(archive)
            or report.get('package_bytes') != archive.stat().st_size):
        fail('Built package does not match this exact validated source commit')
    expected_checksums = digest(archive) + '  ' + archive.name + '\n' + digest(validation) + '  ' + validation.name + '\n'
    if checksums.read_text(encoding='ascii') != expected_checksums:
        fail('Checksum file does not match the exact release files')
    if package_checksum.read_text(encoding='ascii') != digest(archive) + '  ' + archive.name + '\n':
        fail('Package checksum companion differs from the exact ZIP')
    notes = root / 'docs' / ('RELEASE-' + info['version'] + '-en.md')
    text = notes.read_text(encoding='utf-8').strip()
    if not text:
        fail('English release notes are empty')
    text += '\n\nValidation limits: PHP 8.1/8.2 lint, XML/version checks, packaging integrity, and only the standalone tests listed in validation.json. This workflow did not install or upgrade PrestaShop and did not exercise production or external business services.\n'
    release = client.api('POST', '/releases', {'tag_name': info['tag'], 'target_commitish': info['commit'],
        'name': info['module'] + ' ' + info['version'], 'body': text, 'draft': True, 'prerelease': False})
    if (release.get('draft') is not True or release.get('tag_name') != info['tag']
            or release.get('target_commitish') != info['commit']):
        fail('New release draft metadata differs from requested source')
    print('Created new draft release ' + str(release['id']) + '; existing releases are untouched')
    uploaded = []
    verification = []
    for path in (archive, package_checksum, checksums, validation):
        asset = client.upload(release, path)
        uploaded.append(asset)
        verification.append(client.verify_asset(asset, path))
    current = client.api('GET', '/releases/' + str(release['id']))
    if (current.get('draft') is not True or current.get('tag_name') != info['tag']
            or current.get('target_commitish') != info['commit']):
        fail('Draft changed during asset verification')
    if {item['id'] for item in current.get('assets', [])} != {item['id'] for item in uploaded}:
        fail('Draft asset set changed during verification')
    existing_tag = client.api('GET', '/git/ref/tags/' + info['tag'], allow_missing=True)
    if existing_tag is not None:
        client.verify_tag_commit(info)
    result = client.api('PATCH', '/releases/' + str(release['id']), {'draft': False, 'make_latest': 'true'})
    if (result.get('draft') is not False or result.get('tag_name') != info['tag']
            or not result.get('published_at')):
        fail('GitHub did not confirm release publication')
    client.verify_tag_commit(info)
    print(json.dumps({'published': True, 'tag': info['tag'], 'commit': info['commit'],
                      'release_url': result.get('html_url'), 'verified_assets': verification}, indent=2))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('phase', choices=('validate', 'build', 'preflight', 'publish'))
    args = parser.parse_args()
    try:
        globals()[args.phase](Path.cwd())
    except (RuntimeError, ValueError, OSError, zipfile.BadZipFile) as error:
        print('Release stopped: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
