<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Exceptions;

use Closure;
use Exception;
use Lenorix\DatadisClient\Support\PersonalDataRedactor;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Base of every failure raised by this package.
 *
 * `requestSent` says whether the request may have reached Datadis. It is false only for pre-flight
 * failures (configuration, parameter validation, a login that failed before the data request).
 * PSR-18 cannot tell "never sent" from "sent, no answer" (a read timeout is a network exception),
 * so anything that happens while a data request is in flight counts as possibly sent. That matters
 * because Datadis refuses an identical query for 24 hours.
 *
 * The message and the detail are redacted here, so a subclass that interpolates a CUPS or a NIF
 * still cannot leak it.
 *
 * A failure can be serialized (a queued job, a cache): the copy keeps the message, the detail,
 * the fields of its kind and the failures of this package it chains, never the trace, whose
 * arguments may hold a CUPS, a NIF or a credential, nor a chained exception of another library.
 */
abstract class DatadisException extends RuntimeException
{
    /** A redacted, single-line excerpt of what Datadis answered, when it answered. */
    public readonly ?string $detail;

    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?string $detail = null,
        public readonly ?string $endpoint = null,
        public readonly bool $requestSent = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct(PersonalDataRedactor::redact($message), 0, $previous);

        $this->detail = $detail === null ? null : PersonalDataRedactor::excerpt($detail);
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        $fields = [];

        foreach (self::ownProperties(static::class) as $property) {
            if ($property->isInitialized($this)) {
                $fields[$property->getDeclaringClass()->getName()][$property->getName()] = $property->getValue($this);
            }
        }

        $previous = $this->getPrevious();

        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'fields' => $fields,
            'previous' => $previous instanceof self ? $previous : null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(array $data): void
    {
        $this->message = is_string($data['message'] ?? null) ? $data['message'] : '';
        $this->code = is_int($data['code'] ?? null) ? $data['code'] : 0;
        $this->file = '';
        $this->line = 0;
        // Created again here, it would carry the trace of the unserialize() call.
        (new ReflectionProperty(Exception::class, 'trace'))->setValue($this, []);

        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $class => $values) {
            if (! is_string($class) || ! class_exists($class) || ! is_a($this, $class) || ! is_array($values)) {
                continue;
            }

            // A readonly property is set from the class that declares it.
            Closure::bind(function () use ($values): void {
                foreach ($values as $name => $value) {
                    $this->{$name} = $value;
                }
            }, $this, $class)();
        }

        if (($data['previous'] ?? null) instanceof self) {
            (new ReflectionProperty(Exception::class, 'previous'))->setValue($this, $data['previous']);
        }
    }

    /**
     * The properties declared by this package's exception classes, not PHP's own.
     *
     * @param  class-string  $class
     * @return list<ReflectionProperty>
     */
    private static function ownProperties(string $class): array
    {
        $properties = [];

        for ($reflection = new ReflectionClass($class); $reflection !== false && $reflection->getName() !== RuntimeException::class; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                if (! $property->isStatic() && $property->getDeclaringClass()->getName() === $reflection->getName()) {
                    $properties[] = $property;
                }
            }
        }

        return $properties;
    }
}
