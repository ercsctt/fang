<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Proxies\BrightDataProxyAdapter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScraperTesterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ScraperTester/Index');
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
            'use_proxy' => 'boolean',
            'rotate_user_agent' => 'boolean',
        ]);

        try {
            $adapter = new GuzzleHttpAdapter(
                rotateUserAgent: $request->boolean('rotate_user_agent', true),
                rotateProxy: $request->boolean('use_proxy', false),
            );

            // Configure proxy if requested and available
            if ($request->boolean('use_proxy') && config('services.brightdata.username')) {
                $proxyAdapter = new BrightDataProxyAdapter();
                $adapter->withProxy($proxyAdapter);
            }

            $html = $adapter->fetchHtml($request->input('url'));

            return response()->json([
                'success' => true,
                'html' => $html,
                'status_code' => $adapter->getLastStatusCode(),
                'headers' => $adapter->getLastHeaders(),
                'length' => strlen($html),
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
