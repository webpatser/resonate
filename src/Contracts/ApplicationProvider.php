<?php

namespace Webpatser\Resonate\Contracts;

use Illuminate\Support\Collection;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\Exceptions\InvalidApplication;

interface ApplicationProvider
{
    /**
     * Get all of the configured applications as Application instances.
     *
     * @return Collection<Application>
     */
    public function all(): Collection;

    /**
     * Find an application instance by ID.
     *
     * @throws InvalidApplication
     */
    public function findById(string $id): Application;

    /**
     * Find an application instance by key.
     *
     * @throws InvalidApplication
     */
    public function findByKey(string $key): Application;
}
