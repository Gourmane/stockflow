<?php

namespace Tests\Feature\UserManagement;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;

trait RecordsUserManagementResponses
{
    protected function setUpRecordsUserManagementResponses(): void
    {
        $log = getenv('STOCKFLOW_QA_HTTP_LOG');

        if ($log === false || $log === '') {
            return;
        }

        Event::listen(RequestHandled::class, function (RequestHandled $event) use ($log): void {
            $body = json_decode((string) $event->response->getContent(), true);
            $body = is_array($body) ? $body : [];

            $record = [
                'test' => $this->nameWithDataSet(),
                'method' => $event->request->method(),
                'path' => $event->request->getPathInfo(),
                'status' => $event->response->getStatusCode(),
                'json_keys' => array_keys($body),
                'validation_fields' => is_array($body['errors'] ?? null) ? array_keys($body['errors']) : [],
            ];

            file_put_contents($log, json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        });
    }
}
