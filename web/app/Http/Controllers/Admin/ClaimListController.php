<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClaimListController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ]);
        $claims = ClaimRequest::with([
            'post:id,item_name,user_id,type,status',
            'post.author:id,first_name,last_name',
            'claimant:id,first_name,last_name,tmc_id_no,email',
        ])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.claims', ['claims' => $claims]);
    }
}
