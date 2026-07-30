<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request->header('Accept-Language'));
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', $this->varyHeader($response->headers->get('Vary')));

        return $response;
    }

    private function resolveLocale(?string $header): string
    {
        $supported = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $locale): string => strtolower(trim((string) $locale)),
                (array) config('localization.supported_locales', ['en', 'ar']),
            ),
        )));

        $default = $this->configuredLocale((string) config('app.locale', 'en'), $supported)
            ?? $supported[0]
            ?? 'en';

        if ($header === null || trim($header) === '') {
            return $default;
        }

        $candidates = [];

        foreach (explode(',', $header) as $position => $range) {
            $parts = array_map('trim', explode(';', $range));
            $tag = array_shift($parts);

            if ($tag === null || ! preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $tag)) {
                continue;
            }

            $quality = 1.0;
            $valid = true;

            foreach ($parts as $parameter) {
                if (! preg_match('/^q=(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/i', $parameter, $matches)) {
                    $valid = false;
                    break;
                }

                $quality = (float) $matches[1];
            }

            if (! $valid || $quality <= 0) {
                continue;
            }

            $locale = strtolower(explode('-', $tag, 2)[0]);
            if (in_array($locale, $supported, true)) {
                $candidates[] = compact('locale', 'quality', 'position');
            }
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $right['quality'] <=> $left['quality']
                ?: $left['position'] <=> $right['position'],
        );

        if ($candidates !== []) {
            return $candidates[0]['locale'];
        }

        return $this->configuredLocale((string) config('app.fallback_locale', $default), $supported)
            ?? $default;
    }

    /**
     * @param  list<string>  $supported
     */
    private function configuredLocale(string $locale, array $supported): ?string
    {
        $locale = strtolower(explode('-', trim($locale), 2)[0]);

        return in_array($locale, $supported, true) ? $locale : null;
    }

    private function varyHeader(?string $current): string
    {
        $values = array_filter(array_map('trim', explode(',', (string) $current)));

        if (! in_array('Accept-Language', $values, true)) {
            $values[] = 'Accept-Language';
        }

        return implode(', ', $values);
    }
}
