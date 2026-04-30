<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use App\Http\Requests\Api\V1\Test\StoreTestCatalogRequest;

class StoreAdminTestRequest extends StoreTestCatalogRequest
{
    use AuthorizesAdmin;
}
