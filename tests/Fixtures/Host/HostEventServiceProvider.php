<?php

declare(strict_types=1);

namespace UAMSWeb\FormSettings\Tests\Fixtures\Host;

use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Statamic\Events\FormSubmitted;

/**
 * A host application's event service provider, listing its own `FormSubmitted` listener.
 *
 * Laravel registers a host's providers after its packages' providers, so a test
 * boots this one after the add-on's, as a host does.
 */
final class HostEventServiceProvider extends EventServiceProvider
{
    /**
     * The host's listeners, keyed by event.
     *
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        FormSubmitted::class => [RefuseEverySubmission::class],
    ];

    /**
     * Register only the listeners listed above, as the test means them.
     */
    #[\Override]
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
