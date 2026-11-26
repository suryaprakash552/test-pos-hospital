<?php

namespace Tests\Unit\Services;

use App\Constants\CacheKeys;
use App\Models\UserCurrency;
use App\Services\AuthService;
use App\Services\CacheService;
use App\Services\LoggingService;
use Mockery;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_get_user_currency_accepts_a_business_currency_model(): void
    {
        $businessId = 13;
        $currency = new UserCurrency([
            'business_id' => $businessId,
            'name' => 'Indian Rupee',
            'code' => 'INR',
            'symbol' => '₹',
            'position' => 'left',
        ]);

        $cache = Mockery::mock(CacheService::class);
        $cache->shouldReceive('remember')
            ->once()
            ->with(
                CacheKeys::user(CacheKeys::USER_CURRENCY, $businessId),
                Mockery::type(\Closure::class),
                3600,
                [CacheService::TAG_CURRENCIES]
            )
            ->andReturn($currency);

        $service = new AuthService($cache, Mockery::mock(LoggingService::class));

        $this->assertSame($currency, $service->getUserCurrency($businessId));
    }
}
