<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Tests\TestCase;

class PostmanContractTest extends TestCase
{
    public function test_every_postman_request_resolves_to_an_implemented_controller(): void
    {
        $collection = json_decode(file_get_contents(base_path('Nella-and-Vale.postman_collection.json')), true, 512, JSON_THROW_ON_ERROR);
        $walk = function (array $items) use (&$walk): void {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    $walk($item['item']);

                    continue;
                }
                $request = $item['request'];
                $url = str_replace('{{base_url}}', 'http://localhost:8000', $request['url']['raw']);
                $url = preg_replace_callback('/\{\{([^}]+)\}\}/', fn ($m) => match ($m[1]) {
                    'paypal_environment' => 'sandbox','photo_index' => '0',default => '1'
                }, $url);
                $route = app('router')->getRoutes()->match(Request::create($url, $request['method']));
                $this->assertContains($request['method'], $route->methods(), $item['name']);
                if ($controller = $route->getAction('controller')) {
                    [$class,$method] = explode('@', str_contains($controller, '@') ? $controller : $controller.'@__invoke');
                    $this->assertTrue(method_exists($class, $method), $item['name']);
                }
            }
        };
        $walk($collection['item']);
    }
}
