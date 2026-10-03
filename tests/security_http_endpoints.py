"""HTTP boundary checks against real endpoint files and a side-effect-counting PS fixture."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
PHP = os.environ.get('PHP_BINARY', 'php')
BOOTSTRAP = r'''<?php
define('_PS_VERSION_', '8.1.7');
class Tools { public static function getValue($k,$d=false) { return isset($_POST[$k])?$_POST[$k]:(isset($_GET[$k])?$_GET[$k]:$d); } }
class Shop { public static function getShops($a,$g,$ids) { return isset($_GET['fixture_multishop'])?array(1,2):array(1); } }
class Configuration { public static function get($k) { return $k==='SSBHESABFA_INTERNAL_API_USE_QUEUE'?false:'expected'; } }
class Validate { public static function isLoadedObject($v) { return is_object($v)&&$v->id===1; } }
class Module {
 public static function isInstalled($name) { return true; }
 public static function isEnabled($name) { return !isset($_GET['fixture_disabled']); }
 public static function getInstanceByName($name) { return new FixtureModule(); }
}
function fixtureWrite() { file_put_contents(getenv('SSBH_HTTP_SIDE_EFFECTS'),'write'.PHP_EOL,FILE_APPEND); }
class Ssbhesabfa { public static function addLegacyLog() { fixtureWrite(); } }
class FixtureModule { public $id=1,$active=true; public function processPendingHesabfaJobs($limit) { fixtureWrite(); return 0; } }
'''

with tempfile.TemporaryDirectory(prefix='ssbh-http-') as temp:
    temp = Path(temp)
    module = temp / 'modules' / 'ssbhesabfa'
    (module / 'classes').mkdir(parents=True)
    (temp / 'config').mkdir()
    (temp / 'config' / 'config.inc.php').write_text(BOOTSTRAP)
    (temp / 'init.php').write_text('<?php')
    for name in ('ssbhesabfa-cron.php', 'ssbhesabfa-webhook.php', 'classes/HesabfaSecurity.php'):
        shutil.copyfile(str(ROOT / name), str(module / name))
    side_effects = temp / 'effects'
    env = dict(os.environ, SSBH_HTTP_SIDE_EFFECTS=str(side_effects))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with open(str(temp / 'server-output'), 'w') as output:
        server = subprocess.Popen([PHP, '-d', 'display_errors=1', '-S', '127.0.0.1:%d' % port, '-t', str(temp)], env=env, stdout=output, stderr=output)
        try:
            base = 'http://127.0.0.1:%d/modules/ssbhesabfa/' % port
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.01)
            def request(path, data=None, headers=None):
                req = urllib.request.Request(base + path, data=data, headers=headers or {})
                try:
                    response = urllib.request.urlopen(req, timeout=5)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    return response.code, response.read()
            for _ in range(1000):
                assert request('ssbhesabfa-webhook.php?token=wrong', b'{"Password":true}')[0] == 403
            for value in (True, False, 1, 0, None, [], {}, '', 'wrong'):
                assert request('ssbhesabfa-webhook.php?token=expected', json.dumps({'Password': value}).encode())[0] == 403
            assert request('ssbhesabfa-webhook.php?token=expected', b'{invalid')[0] == 403
            assert request('ssbhesabfa-webhook.php?token=expected', b'x' * 65537)[0] == 413
            assert request('ssbhesabfa-webhook.php?token=expected')[0] == 405
            assert request('ssbhesabfa-cron.php?token=expected&webhook_limit=0')[0] == 403
            header = {'X-SSB-Hesabfa-Token': 'expected'}
            assert request('ssbhesabfa-cron.php?fixture_disabled=1&webhook_limit=0', headers=header)[0] == 503
            assert request('ssbhesabfa-cron.php?fixture_multishop=1&webhook_limit=0', headers=header)[0] == 503
            assert not side_effects.exists(), 'Unauthenticated, disabled or multi-shop requests wrote to DB/workers'
            status, body = request('ssbhesabfa-cron.php?webhook_limit=0', headers=header)
            assert status == 200 and json.loads(body)['success'] is True
            assert side_effects.read_text().splitlines() == ['write'], 'Authorized cron did not run exactly once'
            print('PASS: HTTP endpoints, 1000 invalid requests with zero DB logs, strict passwords, size/POST limits, header-only cron, disabled/multi-shop rejection')
        finally:
            server.terminate()
            server.wait(timeout=5)
