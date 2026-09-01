<?php

namespace App\Support;

/**
 * Reads the bits of a serialized queue payload that are safe and useful to
 * show a super admin.
 *
 * A `jobs`/`failed_jobs` row stores the whole job as JSON wrapping a
 * PHP-serialized command string. Unserializing that would instantiate the
 * job (and everything it references), so nothing here does: the class name
 * is read from the JSON envelope, which Laravel populates precisely so a
 * payload can be identified without being rehydrated.
 */
class QueuePayload
{
    /**
     * The job's class name, e.g. "ProcessShopifyOrderWebhookJob", or null
     * when the payload isn't in the shape we expect.
     */
    public static function jobName(?string $payload): ?string
    {
        $decoded = self::decode($payload);

        $name = $decoded['displayName']
            ?? $decoded['data']['commandName']
            ?? $decoded['job']
            ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return class_basename($name);
    }

    /**
     * The fully-qualified class name, kept for the detail view where the
     * namespace is worth seeing.
     */
    public static function jobClass(?string $payload): ?string
    {
        $decoded = self::decode($payload);

        $name = $decoded['displayName']
            ?? $decoded['data']['commandName']
            ?? $decoded['job']
            ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * How many times the job has been attempted, per the payload envelope.
     */
    public static function attempts(?string $payload): ?int
    {
        $attempts = self::decode($payload)['attempts'] ?? null;

        return is_int($attempts) ? $attempts : null;
    }

    /**
     * The first line of an exception trace — the message, without the stack
     * that follows it.
     */
    public static function exceptionSummary(?string $exception): ?string
    {
        if ($exception === null || $exception === '') {
            return null;
        }

        // strtok() only returns false for an empty subject, which the guard
        // above already excludes.
        return trim(strtok($exception, "\n"));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
