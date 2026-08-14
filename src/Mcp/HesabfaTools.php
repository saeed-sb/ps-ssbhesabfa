<?php

namespace SsbHesabfa\Mcp;

use Address;
use Configuration;
use Customer;
use HesabfaIssueRepository;
use HesabfaJobRepository;
use HesabfaLogRepository;
use HesabfaMappingRepository;
use HesabfaModel;
use HesabfaQueueService;
use Module;
use Order;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpSchema;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpTool;
use PrestaShop\Module\PsMcpServer\Server\Attributes\PsMcpToolAnnotations;
use PrestaShop\Module\PsMcpServer\Server\Exceptions\PsMcpToolCallException;
use Product;
use Ssbhesabfa;
use Validate;

/**
 * MCP tools for safe inspection and controlled synchronization of Hesabfa data.
 */
class HesabfaTools
{
    #[PsMcpTool(
        name: 'hesabfa_get_status',
        title: 'Get Hesabfa status',
        description: 'Return a secret-free status summary for the Hesabfa module, including connection flags, queue alerts, mappings, logs, and open issues.',
        annotations: new PsMcpToolAnnotations(title: 'Get Hesabfa status', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        meta: ['category' => 'hesabfa_monitoring']
    )]
    public function getStatus(): array
    {
        try {
            $module = $this->getModule();
            return [
                'module' => [
                    'name' => $module->name,
                    'version' => $module->version,
                    'active' => (bool) $module->active,
                    'mcp_compliant' => true,
                ],
                'connection' => [
                    'api_configured' => (bool) $module->isHesabfaApiConfigured(),
                    'live_mode' => (bool) Configuration::get('SSBHESABFA_LIVE_MODE'),
                    'automatic_sync_enabled' => (bool) $module->isHesabfaSyncEnabled(),
                ],
                'sync_modes' => [
                    'orders_async' => (bool) Configuration::get('SSBHESABFA_ASYNC_ORDER_SYNC'),
                    'products_async' => (bool) Configuration::get('SSBHESABFA_ASYNC_PRODUCT_SYNC'),
                    'customers_async' => (bool) Configuration::get('SSBHESABFA_ASYNC_CUSTOMER_SYNC'),
                ],
                'queue_alerts' => HesabfaJobRepository::getAlertStats(),
                'mappings' => HesabfaMappingRepository::getStats(),
                'logs' => HesabfaLogRepository::getStats(),
                'open_issues' => HesabfaIssueRepository::countByStatus(['open', 'retrying']),
            ];
        } catch (\Throwable $e) {
            $this->fail('Could not read Hesabfa status.', $e);
        }
    }

    #[PsMcpTool(
        name: 'hesabfa_get_mapping',
        title: 'Get Hesabfa mapping',
        description: 'Return the local mapping between one PrestaShop object and its Hesabfa code. Product combinations use attributeId; other object types use zero.',
        annotations: new PsMcpToolAnnotations(title: 'Get Hesabfa mapping', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        meta: ['category' => 'hesabfa_mappings']
    )]
    #[PsMcpSchema(
        properties: [
            'objectType' => ['type' => 'string', 'enum' => ['product', 'customer', 'order', 'returnOrder'], 'description' => 'Mapped object type.'],
            'prestashopId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'PrestaShop product, customer, or order ID.'],
            'attributeId' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Product combination ID, or zero for the base product and non-product objects.'],
        ],
        required: ['objectType', 'prestashopId'],
        additionalProperties: false
    )]
    public function getMapping(string $objectType, int $prestashopId, int $attributeId = 0): array
    {
        try {
            $this->getModule();
            $objectType = $this->validateMappingType($objectType);
            $this->assertPositiveId($prestashopId, 'prestashopId');
            if ($attributeId < 0 || ($objectType !== 'product' && $attributeId !== 0)) {
                throw new \InvalidArgumentException('attributeId must be zero unless objectType is product.');
            }

            $rowId = HesabfaMappingRepository::getObjectRowId($objectType, $prestashopId, $attributeId);
            if ($rowId <= 0) {
                return [
                    'found' => false,
                    'object_type' => $objectType,
                    'prestashop_id' => $prestashopId,
                    'attribute_id' => $attributeId,
                ];
            }

            $mapping = new HesabfaModel($rowId);
            if (!Validate::isLoadedObject($mapping)) {
                throw new \RuntimeException('The mapping row could not be loaded.');
            }

            return [
                'found' => true,
                'mapping_id' => (int) $mapping->id,
                'object_type' => (string) $mapping->obj_type,
                'prestashop_id' => (int) $mapping->id_ps,
                'attribute_id' => (int) $mapping->id_ps_attribute,
                'hesabfa_code' => (int) $mapping->id_hesabfa,
            ];
        } catch (\Throwable $e) {
            $this->fail('Could not read the Hesabfa mapping.', $e);
        }
    }

    #[PsMcpTool(
        name: 'hesabfa_list_jobs',
        title: 'List Hesabfa jobs',
        description: 'List Hesabfa synchronization jobs with optional status, type, and object filters. Results are newest first and capped at 100 rows.',
        annotations: new PsMcpToolAnnotations(title: 'List Hesabfa jobs', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        meta: ['category' => 'hesabfa_queue']
    )]
    #[PsMcpSchema(
        properties: [
            'status' => ['type' => 'string', 'enum' => ['pending', 'running', 'retry_wait', 'needs_attention', 'duplicate_check', 'dead', 'done'], 'description' => 'Optional exact queue status.'],
            'jobType' => ['type' => 'string', 'description' => 'Optional job-type filter, such as sync_product or set_order.'],
            'objectType' => ['type' => 'string', 'description' => 'Optional object-type filter, such as Product, Customer, or Order.'],
            'objectId' => ['type' => 'string', 'description' => 'Optional PrestaShop object-ID filter.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25, 'description' => 'Maximum rows to return.'],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000, 'default' => 0, 'description' => 'Pagination offset.'],
        ],
        additionalProperties: false
    )]
    public function listJobs(string $status = '', string $jobType = '', string $objectType = '', string $objectId = '', int $limit = 25, int $offset = 0): array
    {
        try {
            $this->getModule();
            $limit = max(1, min(100, $limit));
            $offset = max(0, min(10000, $offset));
            $filters = array_filter([
                'status' => trim($status),
                'job_type' => trim($jobType),
                'object_type' => trim($objectType),
                'object_id' => trim($objectId),
            ], static fn ($value) => $value !== '');

            $rows = HesabfaJobRepository::getList($filters, $limit, $offset);

            return [
                'total' => HesabfaJobRepository::countList($filters),
                'limit' => $limit,
                'offset' => $offset,
                'jobs' => array_map([$this, 'formatJob'], $rows),
            ];
        } catch (\Throwable $e) {
            $this->fail('Could not list Hesabfa jobs.', $e);
        }
    }

    #[PsMcpTool(
        name: 'hesabfa_get_job',
        title: 'Get Hesabfa job',
        description: 'Return one synchronization job by ID, including its sanitized payload, status, attempts, error, and execution timestamps.',
        annotations: new PsMcpToolAnnotations(title: 'Get Hesabfa job', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        meta: ['category' => 'hesabfa_queue']
    )]
    #[PsMcpSchema(
        properties: [
            'jobId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Hesabfa queue job ID.'],
        ],
        required: ['jobId'],
        additionalProperties: false
    )]
    public function getJob(int $jobId): array
    {
        try {
            $this->getModule();
            $this->assertPositiveId($jobId, 'jobId');
            $row = HesabfaJobRepository::getById($jobId);
            if (!$row) {
                throw new \RuntimeException('Hesabfa job was not found.');
            }

            return $this->formatJob($row);
        } catch (\Throwable $e) {
            $this->fail('Could not read the Hesabfa job.', $e);
        }
    }

    #[PsMcpTool(
        name: 'hesabfa_list_issues',
        title: 'List Hesabfa issues',
        description: 'List actionable Hesabfa issues by status. Results are newest first and capped at 100 rows.',
        annotations: new PsMcpToolAnnotations(title: 'List Hesabfa issues', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        meta: ['category' => 'hesabfa_monitoring']
    )]
    #[PsMcpSchema(
        properties: [
            'statuses' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['open', 'retrying', 'resolved']], 'minItems' => 1, 'maxItems' => 3, 'uniqueItems' => true, 'default' => ['open', 'retrying'], 'description' => 'Issue statuses to include.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25, 'description' => 'Maximum rows to return.'],
        ],
        additionalProperties: false
    )]
    public function listIssues(array $statuses = ['open', 'retrying'], int $limit = 25): array
    {
        try {
            $this->getModule();
            $allowed = ['open', 'retrying', 'resolved'];
            $statuses = array_values(array_unique(array_intersect($allowed, $statuses)));
            if (!$statuses) {
                throw new \InvalidArgumentException('At least one valid issue status is required.');
            }
            $limit = max(1, min(100, $limit));

            return [
                'statuses' => $statuses,
                'total' => HesabfaIssueRepository::countByStatus($statuses),
                'limit' => $limit,
                'issues' => HesabfaIssueRepository::getByStatus($statuses, $limit),
            ];
        } catch (\Throwable $e) {
            $this->fail('Could not list Hesabfa issues.', $e);
        }
    }

    #[PsMcpTool(
        name: 'hesabfa_queue_sync',
        title: 'Queue Hesabfa synchronization',
        description: 'Validate a PrestaShop object and add a controlled Hesabfa synchronization job for a product, customer, customer address, sales order, or order payment. The tool does not execute the remote request.',
        annotations: new PsMcpToolAnnotations(title: 'Queue Hesabfa synchronization', readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
        meta: ['category' => 'hesabfa_queue']
    )]
    #[PsMcpSchema(
        properties: [
            'entityType' => ['type' => 'string', 'enum' => ['product', 'customer', 'customer_address', 'order', 'payment'], 'description' => 'Synchronization operation to queue.'],
            'prestashopId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Product, customer, or order ID. For customer_address this is the customer ID.'],
            'addressId' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Required address ID when entityType is customer_address; otherwise zero.'],
        ],
        required: ['entityType', 'prestashopId'],
        additionalProperties: false
    )]
    public function queueSync(string $entityType, int $prestashopId, int $addressId = 0): array
    {
        try {
            $module = $this->getModule();
            $this->assertPositiveId($prestashopId, 'prestashopId');
            if (!$module->isHesabfaSyncEnabled()) {
                throw new \RuntimeException('Automatic Hesabfa synchronization is disabled.');
            }
            if (!$module->isHesabfaApiConfigured()) {
                throw new \RuntimeException('Hesabfa API credentials are not configured.');
            }

            [$jobType, $payload, $objectType, $objectId] = $this->buildSyncJob($entityType, $prestashopId, $addressId);
            $jobId = HesabfaJobRepository::enqueue($jobType, $payload, $objectType, $objectId);
            if (!$jobId) {
                throw new \RuntimeException('The synchronization job could not be queued.');
            }

            return [
                'queued' => true,
                'job_id' => (int) $jobId,
                'job_type' => $jobType,
                'object_type' => $objectType,
                'object_id' => (string) $objectId,
            ];
        } catch (\Throwable $e) {
            $this->fail('Could not queue Hesabfa synchronization.', $e);
        }
    }

    #[PsMcpTool(
        name: 'hesabfa_process_job',
        title: 'Process Hesabfa job',
        description: 'Execute one eligible pending or retry-wait Hesabfa job immediately. This can create or update records in Hesabfa and updates the local queue status.',
        annotations: new PsMcpToolAnnotations(title: 'Process Hesabfa job', readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
        meta: ['category' => 'hesabfa_queue']
    )]
    #[PsMcpSchema(
        properties: [
            'jobId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Eligible pending or retry-wait queue job ID.'],
        ],
        required: ['jobId'],
        additionalProperties: false
    )]
    public function processJob(int $jobId): array
    {
        try {
            $module = $this->getModule();
            $this->assertPositiveId($jobId, 'jobId');
            $result = (new HesabfaQueueService($module))->processSingle($jobId);
            $row = HesabfaJobRepository::getById($jobId);

            return [
                'job_id' => $jobId,
                'success' => !empty($result['success']),
                'message' => isset($result['message']) ? (string) $result['message'] : '',
                'status' => $row ? (string) $row['status'] : null,
                'error_code' => $row && !empty($row['last_error_code']) ? (string) $row['last_error_code'] : null,
                'error' => $row && !empty($row['last_error']) ? (string) $row['last_error'] : null,
            ];
        } catch (\Throwable $e) {
            $this->fail('Could not process the Hesabfa job.', $e);
        }
    }

    private function getModule(): Ssbhesabfa
    {
        $module = Module::getInstanceByName('ssbhesabfa');
        if (!$module instanceof Ssbhesabfa || !$module->active) {
            throw new \RuntimeException('The ssbhesabfa module is not installed and active.');
        }

        return $module;
    }

    private function validateMappingType(string $objectType): string
    {
        if (!in_array($objectType, ['product', 'customer', 'order', 'returnOrder'], true)) {
            throw new \InvalidArgumentException('Unsupported Hesabfa mapping object type.');
        }

        return $objectType;
    }

    private function assertPositiveId(int $id, string $field): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException($field . ' must be a positive integer.');
        }
    }

    private function buildSyncJob(string $entityType, int $prestashopId, int $addressId): array
    {
        switch ($entityType) {
            case 'product':
                $this->assertLoaded(new Product($prestashopId), 'Product');
                return ['sync_product', ['id_product' => $prestashopId, 'source_hook' => 'mcp'], 'Product', $prestashopId];

            case 'customer':
                $this->assertLoaded(new Customer($prestashopId), 'Customer');
                return ['sync_customer', ['id_customer' => $prestashopId, 'source_hook' => 'mcp'], 'Customer', $prestashopId];

            case 'customer_address':
                $this->assertPositiveId($addressId, 'addressId');
                $customer = new Customer($prestashopId);
                $address = new Address($addressId);
                $this->assertLoaded($customer, 'Customer');
                $this->assertLoaded($address, 'Address');
                if ((int) $address->id_customer !== $prestashopId) {
                    throw new \InvalidArgumentException('The address does not belong to the requested customer.');
                }
                return ['sync_customer_address', ['id_customer' => $prestashopId, 'id_address' => $addressId, 'source_hook' => 'mcp'], 'Customer', $prestashopId];

            case 'order':
                $this->assertLoaded(new Order($prestashopId), 'Order');
                return ['set_order', ['id_order' => $prestashopId, 'order_type' => 0, 'reference' => null, 'source_hook' => 'mcp'], 'Order', $prestashopId];

            case 'payment':
                $this->assertLoaded(new Order($prestashopId), 'Order');
                return ['set_order_payment', ['id_order' => $prestashopId, 'source_hook' => 'mcp'], 'Order', $prestashopId];

            default:
                throw new \InvalidArgumentException('Unsupported synchronization entityType.');
        }
    }

    private function assertLoaded($object, string $label): void
    {
        if (!Validate::isLoadedObject($object)) {
            throw new \RuntimeException($label . ' was not found.');
        }
    }

    private function formatJob(array $row): array
    {
        $payload = json_decode(isset($row['payload']) ? (string) $row['payload'] : '', true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return [
            'job_id' => (int) $row['id_ssb_hesabfa_job'],
            'job_type' => (string) $row['job_type'],
            'status' => (string) $row['status'],
            'object_type' => isset($row['object_type']) ? (string) $row['object_type'] : '',
            'object_id' => isset($row['object_id']) ? (string) $row['object_id'] : '',
            'payload' => $payload,
            'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : 0,
            'error_code' => !empty($row['last_error_code']) ? (string) $row['last_error_code'] : null,
            'error' => !empty($row['last_error']) ? (string) $row['last_error'] : null,
            'next_run_at' => !empty($row['next_run_at']) ? (string) $row['next_run_at'] : null,
            'finished_at' => !empty($row['finished_at']) ? (string) $row['finished_at'] : null,
            'created_at' => !empty($row['date_add']) ? (string) $row['date_add'] : null,
            'updated_at' => !empty($row['date_upd']) ? (string) $row['date_upd'] : null,
        ];
    }

    private function fail(string $context, \Throwable $exception): void
    {
        $message = $context . ' ' . $exception->getMessage();
        throw new PsMcpToolCallException($message, 1, $exception);
    }
}
