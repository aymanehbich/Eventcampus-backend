<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Notifications\NouvelleAdhesionNotification;
use Illuminate\Http\Request;

class ClubController extends Controller
{
    /**
     * POST /api/clubs
     */
    public function store(Request $request)
    {
        $request->validate([
            'nom'         => 'required|string|max:255',
            'description' => 'required|string',
            'categorie'   => 'required|string|max:100',
            'emoji'       => 'nullable|string|max:10',
            'logo'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('logos', 'public');
        }

        $club = Club::create([
            'nom'         => $request->nom,
            'description' => $request->description,
            'categorie'   => $request->categorie,
            'emoji'       => $request->emoji ?? '🎓',
            'createur_id' => $request->user()->id,
            'statut'      => 'en_attente',
            'logo'        => $logoPath,
        ]);

        $club->logo_url = $logoPath ? asset('storage/' . $logoPath) : null;

        return response()->json([
            'message' => 'Club créé avec succès. Il est en attente de validation.',
            'club'    => $club,
        ], 201);
    }

    /**
     * GET /api/clubs
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $query = Club::withCount('membres')
            ->withExists(['membres as is_member' => fn($q) => $q->where('users.id', $userId)])
            ->where('statut', 'validé');

        if ($request->has('categorie') && $request->categorie !== 'Tous') {
            $query->where('categorie', $request->categorie);
        }

        $clubs = $query->latest()->get();

        return response()->json($clubs);
    }

    /**
     * GET /api/clubs/{id}
     */
    public function show(Request $request, $id)
{
    $club = Club::with([
            'createur' => fn($q) => $q->select('id', 'prenom', 'nom', 'name', 'email'),
            'membres' => function($q) {
                $q->select('users.id', 'prenom', 'nom')
                  ->withPivot('role');
            },
            //événements à venir du club
            'evenements' => function($q) {
                $q->where('date', '>=', now()->toDateString())
                  ->orderBy('date', 'asc')
                  ->orderBy('heure', 'asc')
                  ->select('id', 'titre', 'date', 'heure', 'lieu', 'categorie', 'club_id')
                  ->limit(5);
            },
        ])
        ->withCount(['membres', 'evenements']) 
        ->findOrFail($id);

    $userId = $request->user()->id;
    $user   = $request->user();

    if ($club->statut !== 'validé' && $club->createur_id !== $userId && $user->role !== 'admin') {
        return response()->json(['message' => 'Ce club n\'est pas encore publié.'], 403);
    }

    $club->is_member = $club->membres->contains('id', $userId);

    $club->is_pending = \App\Models\Adhesion::where('user_id', $userId)
        ->where('club_id', $club->id)
        ->where('statut', 'en_attente')
        ->exists();

    $club->logo_url = $club->logo ? asset('storage/' . $club->logo) : null;

    $club->organizer = $club->createur ? [
        'id'   => $club->createur->id,
        'name' => $club->createur->name,
    ] : null;

    return response()->json($club);
}



    /**
     * GET /api/profil/clubs
     */
    public function mesClubs(Request $request)
    {
        $userId = $request->user()->id;

        $clubs = Club::withCount('membres')
            ->whereHas('membres', fn($q) => $q->where('user_id', $userId))
            ->latest()
            ->get()
            ->map(fn($club) => [
                'id'            => $club->id,
                'nom'           => $club->nom,
                'description'   => $club->description,
                'categorie'     => $club->categorie,
                'emoji'         => $club->emoji,
                'membres_count' => $club->membres_count,
                'is_member'     => true,
            ]);

        return response()->json($clubs);
    }

    /**
     * GET /api/organiser/clubs
     * Get all clubs created by the authenticated organizer
     */
    public function mesClubsCreated(Request $request)
    {
        $userId = $request->user()->id;

        $clubs = Club::withCount('membres')
            ->where('createur_id', $userId)
            ->latest()
            ->get()
            ->map(fn($club) => [
                'id'            => $club->id,
                'nom'           => $club->nom,
                'description'   => $club->description,
                'categorie'     => $club->categorie,
                'emoji'         => $club->emoji,
                'statut'        => $club->statut,
                'membres_count' => $club->membres_count,
                'created_at'    => $club->created_at,
            ]);

        return response()->json($clubs);
    }

    /**
     * GET /api/organiser/membres
     */
    public function membresOrganisateur(Request $request)
    {
        $userId = $request->user()->id;

        $clubQuery = Club::where('createur_id', $userId);

        if ($request->has('club_id')) {
            $clubQuery->where('id', $request->club_id);
        }

        $clubs = $clubQuery
            ->with(['membres' => function ($q) {
                $q->select('users.id', 'users.prenom', 'users.nom', 'users.name', 'users.email')
                  ->withPivot('role', 'created_at');
            }])
            ->get(['id', 'nom']);

        $membres = [];
        foreach ($clubs as $club) {
            foreach ($club->membres as $m) {
                $membres[] = [
                    'user_id'   => $m->id,
                    'prenom'    => $m->prenom,
                    'nom'       => $m->nom,
                    'name'      => $m->name,
                    'email'     => $m->email,
                    'club_id'   => $club->id,
                    'club_nom'  => $club->nom,
                    'role'      => $m->pivot->role,
                    'joined_at' => $m->pivot->created_at,
                ];
            }
        }

        return response()->json($membres);
    }

    /**
     * POST /api/clubs/{id}/update
     */
    public function update(Request $request, $id)
    {
        $club = Club::findOrFail($id);

        if ($club->createur_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $request->validate([
            'nom'         => 'required|string|max:255',
            'description' => 'required|string',
            'categorie'   => 'required|string|max:100',
            'emoji'       => 'nullable|string|max:10',
            'logo'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            if ($club->logo) {
                \Storage::disk('public')->delete($club->logo);
            }
            $club->logo = $request->file('logo')->store('logos', 'public');
        }

        $club->nom         = $request->nom;
        $club->description = $request->description;
        $club->categorie   = $request->categorie;
        $club->emoji       = $request->emoji ?? $club->emoji;
        $club->save();

        $club->logo_url = $club->logo ? asset('storage/' . $club->logo) : null;

        return response()->json([
            'message' => 'Club mis à jour avec succès.',
            'club'    => $club,
        ]);
    }

    /**
     * POST /api/adhesions
     */
    public function adherer(Request $request)
    {
        $request->validate([
            'club_id' => 'required|exists:clubs,id',
        ]);

        $club   = Club::findOrFail($request->club_id);
        $userId = $request->user()->id;

        if ($club->membres()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Vous êtes déjà membre de ce club.'], 409);
        }

        $club->membres()->attach($userId, ['role' => 'membre']);

        $club->loadMissing('createur');
        if ($club->createur) {
            $club->createur->notify(new NouvelleAdhesionNotification($club, $request->user()));
        }

        return response()->json(['message' => 'Vous avez rejoint le club avec succès.'], 201);
    }

    /**
     * POST /api/clubs/{id}/leave
     */
    public function leave(Request $request, $id)
    {
        $club   = Club::findOrFail($id);
        $userId = $request->user()->id;

        if (!$club->membres()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Vous n\'êtes pas membre de ce club.'], 409);
        }

        $club->membres()->detach($userId);

        return response()->json(['message' => 'Vous avez quitté le club avec succès.']);
    }
}