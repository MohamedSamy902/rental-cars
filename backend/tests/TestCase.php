<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** When false, postJson does not auto-fill newly required create fields. */
    protected bool $injectRequiredCreateFields = true;

    /**
     * Inject newly-required create fields so legacy feature fixtures keep working.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function postJson($uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        if ($this->injectRequiredCreateFields) {
            $path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) ?: $uri : '';

            if ($path === '/api/vehicles' && $data !== [] && empty($data['chassis_number'])) {
                $data['chassis_number'] = 'CHS'.substr(sha1(json_encode($data) ?: uniqid('', true)), 0, 10);
            }

            if ($path === '/api/vehicles' && isset($data['owner_inline']) && is_array($data['owner_inline'])) {
                $data['owner_inline']['phone'] ??= '01012345678';
                $data['owner_inline']['national_id'] ??= '29001011234567';
                $data['owner_inline']['id_front_path'] ??= 'tests/owners/id-front.jpg';
                $data['owner_inline']['id_back_path'] ??= 'tests/owners/id-back.jpg';
            }

            if ($path === '/api/owners' && $data !== []) {
                $data['phone'] ??= '01012345678';
                $data['national_id'] ??= '29001011234567';
                $data['id_front_path'] ??= 'tests/owners/id-front.jpg';
                $data['id_back_path'] ??= 'tests/owners/id-back.jpg';
            }
        }

        return parent::postJson($uri, $data, $headers, $options);
    }
}
