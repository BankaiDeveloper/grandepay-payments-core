<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ExampleTest extends TestCase
{
    public function testTheHealthEndpointReturnsSuccessfulResponse(): void
    {
        $this->get('/up')
            ->assertSuccessful()
            ->assertJson([
                'status' => 'ok',
                'service' => 'grandepay-payments-core',
            ]);
    }

    public function testTheSystemInfoEndpointReturnsServiceMetadata(): void
    {
        $this->get('/api/v1/system/info')
            ->assertSuccessful()
            ->assertJson([
                'service' => 'grandepay-payments-core',
                'api_version' => 'v1',
                'framework' => 'hypervel',
                'status' => 'bootstrapped',
            ]);
    }
}
