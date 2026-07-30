<?php

namespace Tests\Feature\Api\V1\Localization;

use App\Support\Recommendation\RecommendationReasonTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_header_uses_configured_default_locale(): void
    {
        config(['app.locale' => 'en']);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertHeader('Vary', 'Accept-Language')
            ->assertJsonPath('message', 'Home data retrieved successfully.')
            ->assertJsonPath('data.hero.title', 'Your next job starts here');
    }

    public function test_supported_simple_and_regional_locales_are_selected(): void
    {
        $this->withHeader('Accept-Language', 'ar-SY')
            ->getJson('/api/v1/home')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', 'تم استرجاع بيانات الصفحة الرئيسية بنجاح.');

        $this->withHeader('Accept-Language', 'en-US')
            ->getJson('/api/v1/home')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.hero.title', 'Your next job starts here');
    }

    public function test_quality_values_and_original_order_are_respected(): void
    {
        $this->withHeader('Accept-Language', 'en-US;q=0.4, ar-SA;q=0.9')
            ->getJson('/api/v1/home')
            ->assertHeader('Content-Language', 'ar');

        $this->withHeader('Accept-Language', 'en-US,en;q=0.9,ar;q=0.8')
            ->getJson('/api/v1/home')
            ->assertHeader('Content-Language', 'en');
    }

    public function test_unsupported_or_malformed_header_uses_fallback_without_crashing(): void
    {
        config(['app.fallback_locale' => 'ar']);

        foreach (['fr-FR,de;q=0.8', '@@@;q=nope', 'en;q=2'] as $header) {
            $this->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/home')
                ->assertOk()
                ->assertHeader('Content-Language', 'ar');
        }
    }

    public function test_validation_messages_and_attribute_names_are_localized(): void
    {
        $english = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/auth/login', []);
        $arabic = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/auth/login', []);

        $english->assertUnprocessable()
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.email.0', 'The email address field is required.');

        $arabic->assertUnprocessable()
            ->assertJsonPath('message', 'البيانات المقدمة غير صالحة.')
            ->assertJsonPath('errors.email.0', 'حقل البريد الإلكتروني مطلوب.');

        $this->assertSame(array_keys($english->json('errors')), array_keys($arabic->json('errors')));
    }

    public function test_authentication_error_changes_language_while_contract_stays_stable(): void
    {
        $payload = ['email' => 'missing@example.com', 'password' => 'password'];

        $english = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/auth/login', $payload)
            ->assertUnauthorized();
        $arabic = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/auth/login', $payload)
            ->assertUnauthorized();

        $english->assertJsonPath('success', false)->assertJsonPath('message', 'Invalid credentials.');
        $arabic->assertJsonPath('success', false)->assertJsonPath('message', 'بيانات تسجيل الدخول غير صحيحة.');
        $this->assertSame($english->json('code'), $arabic->json('code'));
    }

    public function test_language_does_not_leak_between_sequential_responses(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/home')
            ->assertJsonPath('data.hero.title', 'وظيفتك المناسبة تبدأ من هنا');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/home')
            ->assertJsonPath('data.hero.title', 'Your next job starts here');
    }

    public function test_structured_recommendation_reason_is_translated_without_changing_code(): void
    {
        app()->setLocale('ar');
        $reason = RecommendationReasonTranslator::translate([
            'code' => 'REQUIRED_SKILLS_MATCH',
            'message' => 'Matched 2 of 3 required skills.',
            'value' => 66.67,
        ]);

        $this->assertSame('REQUIRED_SKILLS_MATCH', $reason['code']);
        $this->assertSame(66.67, $reason['value']);
        $this->assertSame('تطابقت 2 مهارة من أصل 3 مهارات مطلوبة.', $reason['message']);
    }
}
