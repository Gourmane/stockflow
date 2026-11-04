<?php

namespace App\Http\Controllers;

use App\Models\User;

class DatabaseCheckController extends Controller
{
    public function index()
    {
        return response()->json([
            'message' => 'Database connected',
            'total_users' => User::count(),
        ]);
    }
}