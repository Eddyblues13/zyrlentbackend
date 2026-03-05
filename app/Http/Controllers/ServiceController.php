<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index()
    {
        $services = Service::where('is_active', true)
            ->select('id', 'name', 'icon', 'color', 'category', 'cost')
            ->orderBy('name')
            ->get();

        return response()->json($services);
    }
}
