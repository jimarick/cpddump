<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

/**
 * The S3 landing zone for inbound email: raw messages live here only
 * between SES delivery and successful ingestion. Swappable in tests.
 */
class SesObjectStore
{
    public function get(string $bucket, string $key): ?string
    {
        try {
            $result = $this->client()->getObject(['Bucket' => $bucket, 'Key' => $key]);

            return (string) $result['Body'];
        } catch (AwsException $e) {
            report($e);

            return null;
        }
    }

    public function delete(string $bucket, string $key): void
    {
        try {
            $this->client()->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (AwsException $e) {
            report($e);
        }
    }

    private function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => config('services.ses_inbound.region'),
            'credentials' => [
                'key' => config('services.ses_inbound.key'),
                'secret' => config('services.ses_inbound.secret'),
            ],
        ]);
    }
}
