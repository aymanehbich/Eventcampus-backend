<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Categorie;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Notifications\NouvelleInscriptionNotification;
use App\Notifications\EvenementModifieNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\EventWaitlist;

class EventController extends Controller
{
    /**
     * GET /api/events/categories
     */
    public function categories()
    {
        $categories = Categorie::orderBy('nom')->get(['nom', 'emoji']);
        return response()->json($categories);
    }

    private function syncCategorie(string $nom, ?string $emoji = null): void
    {
        $nom = trim($nom);
        if (!$nom) return;
        $existing = Categorie::whereRaw('LOWER(nom) = ?', [mb_strtolower($nom)])->first();
        if (!$existing) {
            Categorie::create(['nom' => $nom, 'emoji' => $emoji ?: '📅']);
        } elseif ($emoji && !$existing->emoji) {
            $existing->update(['emoji' => $emoji]);
        }
    }

    /**
     * GET /api/events
     */
    public function index(Request $request)
    {
        $query = Event::with('club')
            ->withCount(['participants', 'listeAttente as waitlist_count'])
            ->whereIn('statut', ['Validé', 'Accepté']);

        // Filtres optionnels
        if ($request->has('categorie') && $request->categorie !== 'Tous') {
            $query->where('categorie', $request->categorie);
        }

        if ($request->has('club_id')) {
            $query->where('club_id', $request->club_id);
        }

        $events = $query->latest()->get();

        return response()->json($this->formatEventList($events));
    }

    /**
     * GET /api/events/{id}
     */
    public function show(Request $request, $id)
    {
        $event = Event::with(['club', 'participants'])
            ->findOrFail($id);

        $user = $request->user();
        $isOwner = $event->club && $event->club->createur_id === $user->id;

        if (!in_array($event->statut, ['Validé', 'Accepté']) && !$isOwner && $user->role !== 'admin') {
            return response()->json(['message' => 'Cet événement n\'est pas encore publié.'], 403);
        }

        // Appel de formatEvent avec $detailed = true
        return response()->json($this->formatEvent($event, true));
    }

    /**
     * POST /api/inscriptions
     */
    public function inscrire(Request $request)
    {
        $request->validate([
            'event_id' => 'required|exists:events,id',
        ]);

        $user  = auth('api')->user();
        $event = Event::findOrFail($request->event_id);

        if ($event->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Vous êtes déjà inscrit à cet événement.'], 409);
        }

        // Logique de gestion de la liste d'attente ou inscription directe
        if ($event->places_disponibles !== null && $event->places_disponibles <= 0) {
            // Ici, vous devriez avoir votre logique pour ajouter à la liste d'attente
            // Exemple : $event->listeAttente()->create(['user_id' => $user->id]);
            return response()->json(['message' => 'Ajouté à la liste d\'attente.'], 200);
        }

        $event->participants()->attach($user->id);

        if ($event->places_disponibles !== null) {
            $event->decrement('places_disponibles');
        }

        $event->loadMissing('club.createur');
        $organisateur = $event->club->createur;
        if ($organisateur) {
            $organisateur->notify(new NouvelleInscriptionNotification($event, $user));
        }

        return response()->json(['message' => 'Inscription réussie.'], 201);
    }

    /**
     * GET /api/organiser/events
     */
    public function mesEvenements()
    {
        $user = auth('api')->user();

        $events = Event::with('club')
            ->withCount(['participants', 'listeAttente as waitlist_count'])
            ->whereHas('club', fn($q) => $q->where('createur_id', $user->id))
            ->latest()
            ->get();

        return response()->json($this->formatEventList($events));
    }

    /**
     * POST /api/events
     */
    public function store(StoreEventRequest $request)
    {
        $event = Event::create([
            'titre' => $request->validated()['titre'],
            'description' => $request->validated()['description'],
            'date' => $request->validated()['date'],
            'heure' => $request->validated()['heure'],
            'date_fin' => $request->validated()['date_fin'],
            'heure_fin' => $request->validated()['heure_fin'],
            'lieu' => $request->validated()['lieu'],
            'categorie' => $request->validated()['categorie'],
            'club_id' => $request->validated()['club_id'],
            'capacite_max' => $request->validated()['capacite_max'],
            'places_disponibles' => $request->validated()['capacite_max'],
            'statut' => 'En attente',
            'user_id' => auth()->id(),
        ]);

        $this->syncCategorie($event->categorie, $request->input('categorie_emoji'));

        $event->load('club');

        return response()->json(
            $this->formatEvent($event, false),
            201
        );
    }

    /**
     * DELETE /api/events/{id}
     */
    public function destroy($id)
    {
        $event = Event::findOrFail($id);
        $user  = auth('api')->user();

        // Seul l'organisateur du club propriétaire peut supprimer
        if ($event->club->createur_id !== $user->id) {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        $event->delete();

        return response()->json(['message' => 'Événement supprimé avec succès.']);
    }

    /**
     * PUT /api/events/{id}
     */
    public function update(UpdateEventRequest $request, $id)
    {
        $event = Event::findOrFail($id);

        // Update all fields with validated data
        $event->update($request->validated());

        $event->update(['statut' => 'En attente']);

        $this->syncCategorie($event->categorie, $request->input('categorie_emoji'));

        $event->load('club');

        // Notify club creator
        $event->club->createur->notify(new EvenementModifieNotification($event));

        $inscrits = $event->participants()->count();
        $estComplet = $event->capacite_max ? ($inscrits >= $event->capacite_max) : false;

        return response()->json([
            'id' => $event->id,
            'titre' => $event->titre,
            'description' => $event->description,
            'date' => $event->date,
            'heure' => $event->heure,
            'date_fin' => $event->date_fin,
            'heure_fin' => $event->heure_fin,
            'lieu' => $event->lieu,
            'categorie' => $event->categorie,
            'club_id' => $event->club_id,
            'capacite_max' => $event->capacite_max,
            'places_disponibles' => $event->places_disponibles,
            'inscrits' => $inscrits,
            'est_complet' => $estComplet,
            'statut' => $event->statut,
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ], 200);
    }

    /**
     * GET /api/events/{id}/inscrits
     */
    public function inscrits($id)
    {
        $event = Event::findOrFail($id);

        $inscrits = $event->participants()
            ->select('users.id', 'users.name', 'users.email')
            ->withPivot('created_at')
            ->orderBy('event_user.created_at')
            ->get()
            ->map(fn($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'inscrit_le' => $u->pivot->created_at,
            ]);

        return response()->json($inscrits);
    }

    /**
     * Formate l'événement pour la réponse JSON
     */
    /**
     * Formate une liste d'événements avec un nombre constant de requêtes
     * (compteurs via withCount + inscriptions/liste d'attente de l'utilisateur préchargées).
     */
    private function formatEventList($events): array
    {
        $user = Auth::user() ?? auth('api')->user();
        $userId = $user?->id;
        $ids = $events->pluck('id');

        $registered = collect();
        $waitlisted = collect();
        if ($userId && $ids->isNotEmpty()) {
            $registered = DB::table('event_user')
                ->where('user_id', $userId)
                ->whereIn('event_id', $ids)
                ->pluck('calendar_added', 'event_id');
            $waitlisted = EventWaitlist::where('user_id', $userId)
                ->where('statut', 'en_attente')
                ->whereIn('event_id', $ids)
                ->pluck('position', 'event_id');
        }

        return $events->map(fn($event) => $this->formatEvent($event, false, [
            'registered' => $registered,
            'waitlisted' => $waitlisted,
        ]))->values()->all();
    }

    private function formatEvent(Event $event, bool $detailed = false, ?array $prefetched = null): array
    {
        $user = Auth::user() ?? auth('api')->user();
        $userId = $user?->id;

        if ($prefetched !== null) {
            $isRegistered  = $prefetched['registered']->has($event->id);
            $isWaitlisted  = $prefetched['waitlisted']->has($event->id);
            $waitlistPos   = $isWaitlisted ? $prefetched['waitlisted']->get($event->id) : null;
            $calendarAdded = $isRegistered && (bool) $prefetched['registered']->get($event->id);
        } else {
            $isRegistered  = $userId ? $event->estInscrit($userId) : false;
            $isWaitlisted  = $userId ? $event->estEnListeAttente($userId) : false;
            $waitlistPos   = $isWaitlisted ? $event->rangListeAttente($userId) : null;
            $calendarAdded = false;
            if ($isRegistered && $userId) {
                $pivot = $event->participants()->where('user_id', $userId)->first()?->pivot;
                $calendarAdded = (bool) ($pivot?->calendar_added ?? false);
            }
        }

        $inscrits = $event->inscrits;
        $waitlistTotal = $event->waitlist_count ?? $event->listeAttente()->count();

        $data = [
            'id'                  => $event->id,
            'titre'               => $event->titre,
            'description'         => $event->description,
            'date'                => $event->date,
            'heure'               => $event->heure,
            'date_fin'            => $event->date_fin,
            'heure_fin'           => $event->heure_fin,
            'lieu'                => $event->lieu,
            'categorie'           => $event->categorie,
            'places_disponibles'  => $event->places_disponibles,
            'capacite_max'        => $event->capacite_max,
            'inscrits'            => $inscrits,
            'est_complet'         => ($event->capacite_max && $inscrits >= $event->capacite_max) ? true : false,
            'statut'              => $event->statut,
            'club_id'             => $event->club_id,
            'club_name'           => $event->club?->nom,
            // Statut de l'utilisateur connecté
            'is_registered'       => $isRegistered,
            'is_waitlisted'       => $isWaitlisted,
            'waitlist_position'   => $waitlistPos,
            'calendar_added'      => $calendarAdded,
            // NOUVEAU — Branche 3 : Total de la liste d'attente
            'waitlist_total'      => $waitlistTotal,
        ];

        if ($detailed) {
            $data['club_members_count'] = $event->club?->membres()->count() ?? 0;
            $data['club_events_count']  = $event->club?->evenements()->count() ?? 0;
            
            
            // NOUVEAU — Branche 3 : Détails de la liste d'attente pour la vue détaillée
            $data['waitlist_details'] = $event->listeAttente()
                ->with('user:id,name,email')
                ->get()
                ->map(fn($w) => [
                    'position' => $w->position,
                    'name'     => $w->user->name ?? $w->user->prenom . ' ' . $w->user->nom,
                ]);
        }

        return $data;
    }
}