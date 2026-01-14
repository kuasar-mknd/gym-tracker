<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'API documentation for Gym Tracker application',
    title: 'Gym Tracker API'
)]
#[OA\Server(
    url: '/api/v1',
    description: 'API V1 Server'
)]
class Swagger
{
    #[OA\Get(
        path: '/api/v1/status',
        summary: 'Status check',
        tags: ['System']
    )]
    #[OA\Response(
        response: 200,
        description: 'OK'
    )]
    public function status()
    {
        //
    }
}
