<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Pet;

class PetController extends Controller
{
    // ── LIST ──────────────────────────────────────────────
    // GET /api/pets?archived=0   active pets (default, used by booking form)
    // GET /api/pets?archived=1   archived pets (My Pets page toggle)
    public function index(Request $request)
    {
        $archived = (int) $request->query('archived', 0);

        $pets = Pet::where('user_id', $request->user()->user_id)
                   ->where('is_archived', $archived)
                   ->orderBy('created_at', 'desc')
                   ->get();

        return response()->json([
            'success' => true,
            'pets'    => $pets,
        ]);
    }

    // ── ADD ───────────────────────────────────────────────
    // POST /api/pets
    public function store(Request $request)
    {
        $data = $request->validate([
            'pet_name'          => 'required|string|max:100',
            'species'           => 'nullable|string|max:50',
            'breed'             => 'nullable|string|max:100',
            'size'              => 'nullable|in:small,medium,large,extra_large',
            'fur_type'          => 'nullable|in:short,medium,long,wire,curl',
            'weight'            => 'nullable|numeric|min:0',
            'color'             => 'nullable|string|max:50',
            'medical_conditions'=> 'nullable|string|max:1000',
        ]);

        $pet = Pet::create([
            'user_id'           => $request->user()->user_id,
            'pet_name'          => $data['pet_name'],
            'species'           => $data['species'] ?? 'Dog',
            'breed'             => $data['breed'] ?? null,
            'size'              => $data['size'] ?? null,
            'fur_type'          => $data['fur_type'] ?? null,
            'weight'            => $data['weight'] ?? null,
            'color'             => $data['color'] ?? null,
            'medical_conditions'=> $data['medical_conditions'] ?? null,
            'is_archived'       => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pet added successfully.',
            'pet'     => $pet,
        ], 201);
    }

    // ── UPDATE ────────────────────────────────────────────
    // PUT /api/pets/{id}
    public function update(Request $request, $id)
    {
        $pet = Pet::where('pet_id', $id)
                  ->where('user_id', $request->user()->user_id)
                  ->first();

        if (!$pet) {
            return response()->json(['success' => false, 'message' => 'Pet not found.'], 404);
        }

        $data = $request->validate([
            'pet_name'          => 'required|string|max:100',
            'species'           => 'nullable|string|max:50',
            'breed'             => 'nullable|string|max:100',
            'size'              => 'nullable|in:small,medium,large,extra_large',
            'fur_type'          => 'nullable|in:short,medium,long,wire,curl',
            'weight'            => 'nullable|numeric|min:0',
            'color'             => 'nullable|string|max:50',
            'medical_conditions'=> 'nullable|string|max:1000',
        ]);

        $pet->update([
            'pet_name'          => $data['pet_name'],
            'species'           => $data['species'] ?? 'Dog',
            'breed'             => $data['breed'] ?? null,
            'size'              => $data['size'] ?? null,
            'fur_type'          => $data['fur_type'] ?? null,
            'weight'            => $data['weight'] ?? null,
            'color'             => $data['color'] ?? null,
            'medical_conditions'=> $data['medical_conditions'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pet updated successfully.',
            'pet'     => $pet->fresh(),
        ]);
    }

    // ── ARCHIVE ───────────────────────────────────────────
    // POST /api/pets/{id}/archive
    public function archive(Request $request, $id)
    {
        $pet = Pet::where('pet_id', $id)
                  ->where('user_id', $request->user()->user_id)
                  ->first();

        if (!$pet) {
            return response()->json(['success' => false, 'message' => 'Pet not found.'], 404);
        }

        $pet->update(['is_archived' => 1]);

        return response()->json(['success' => true, 'message' => 'Pet archived.']);
    }

    // ── UNARCHIVE ─────────────────────────────────────────
    // POST /api/pets/{id}/unarchive
    public function unarchive(Request $request, $id)
    {
        $pet = Pet::where('pet_id', $id)
                  ->where('user_id', $request->user()->user_id)
                  ->first();

        if (!$pet) {
            return response()->json(['success' => false, 'message' => 'Pet not found.'], 404);
        }

        $pet->update(['is_archived' => 0]);

        return response()->json(['success' => true, 'message' => 'Pet restored.']);
    }
}
