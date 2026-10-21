<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Message;
use App\Models\Conversation;
use App\Models\User;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\BroadcastMessageRequest;
use App\Notifications\NouveauMessageNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function getConversations(Request $request): JsonResponse
    {
        $user = auth()->user();
        $type = $request->query('type', 'individuel');

        $conversations = $user->conversations()
            ->where('type', $type)
            ->with(['creator', 'participants', 'messages' => function ($query) {
                $query->latest()->limit(1);
            }])
            ->orderBy('conversations.updated_at', 'desc')
            ->get()
            ->map(function ($conversation) {
                $lastMessage = $conversation->messages->first();
                return [
                    'id' => $conversation->id,
                    'type' => $conversation->type,
                    'creator_id' => $conversation->creator_id,
                    'name' => $conversation->name,
                    'member_id' => $conversation->participants->where('id', '!=', auth()->id())->first()?->id,
                    'participants' => $conversation->participants,
                    'last_message' => $lastMessage,
                    'created_at' => $conversation->created_at,
                    'updated_at' => $conversation->updated_at,
                ];
            });

        return response()->json([
            'message' => 'Vos conversations.',
            'data' => $conversations,
        ]);
    }

    public function createConversation(Request $request): JsonResponse
    {
        $user = auth()->user();
        $memberId = $request->input('member_id');

        if (!$memberId) {
            return response()->json([
                'message' => 'L\'ID du membre est obligatoire.',
            ], 400);
        }

        $member = User::findOrFail($memberId);

        if ($member->id === $user->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas créer une conversation avec vous-même.',
            ], 400);
        }

        $existingConversation = Conversation::where('type', 'individuel')
            ->where('creator_id', $user->id)
            ->whereHas('participants', function ($query) use ($member) {
                $query->where('user_id', $member->id);
            })
            ->first();

        if ($existingConversation) {
            return response()->json([
                'message' => 'Conversation existante récupérée.',
                'data' => [
                    'id' => $existingConversation->id,
                    'type' => $existingConversation->type,
                    'creator_id' => $existingConversation->creator_id,
                    'name' => $existingConversation->name,
                    'member_id' => $member->id,
                    'participants' => $existingConversation->participants,
                    'created_at' => $existingConversation->created_at,
                    'updated_at' => $existingConversation->updated_at,
                ],
            ]);
        }

        $conversation = Conversation::create([
            'type' => 'individuel',
            'creator_id' => $user->id,
        ]);

        $conversation->participants()->attach([$user->id, $member->id]);

        return response()->json([
            'message' => 'Conversation créée avec succès.',
            'data' => [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'creator_id' => $conversation->creator_id,
                'name' => $conversation->name,
                'member_id' => $member->id,
                'participants' => $conversation->participants,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ],
        ], 201);
    }

    public function getMessages($conversationId): JsonResponse
    {
        $user = auth()->user();

        if (is_numeric($conversationId)) {
            $conversation = Conversation::findOrFail($conversationId);

            if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
                return response()->json([
                    'message' => 'Vous n\'avez pas accès à cette conversation.',
                ], 403);
            }

            $messages = $conversation->messages()
                ->with(['sender', 'receiver'])
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'message' => 'Messages de la conversation.',
                'data' => $messages,
            ]);
        }

        return response()->json([
            'message' => 'Conversation introuvable.',
            'data' => [],
        ], 404);
    }

  public function sendMessage($conversationId, SendMessageRequest $request): JsonResponse
{
    $user = auth()->user();
    $recipientId = $request->input('recipient_id');

    // Existing conversation
    if (is_numeric($conversationId)) {

        $conversation = Conversation::findOrFail($conversationId);

        // Check access
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'Vous n\'avez pas accès à cette conversation.',
            ], 403);
        }

    }
    // Create new conversation
    elseif (str_starts_with($conversationId, 'new_') && $recipientId) {

        $recipient = User::findOrFail($recipientId);

        if ($recipient->id === $user->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas créer une conversation avec vous-même.',
            ], 400);
        }

        $conversation = Conversation::create([
            'type' => 'individuel',
            'creator_id' => $user->id,
        ]);

        $conversation->participants()->attach([
            $user->id,
            $recipient->id
        ]);

    } else {

        return response()->json([
            'message' => 'ID de conversation invalide.',
        ], 400);

    }

    // Get receiver id (the other participant)
    $receiverId = $conversation->participants()
        ->where('user_id', '!=', $user->id)
        ->first()?->id;

    // Optional attachment
    $attachmentPath = null;
    $attachmentName = null;
    if ($request->hasFile('file')) {
        $file = $request->file('file');
        $attachmentName = $file->getClientOriginalName();
        $attachmentPath = $file->store('message_attachments', 'public');
    }

    // Create message
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'receiver_id' => $receiverId,
        'content' => $request->validated()['content'] ?? '',
        'attachment_path' => $attachmentPath,
        'attachment_name' => $attachmentName,
        'is_read' => false,
    ]);

    $message->load(['sender', 'receiver']);

    return response()->json([
        'message' => 'Message envoyé avec succès.',
        'data' => [
            'message' => $message,
            'conversation_id' => $conversation->id,
        ],
    ], 201);
}

    public function searchMembers(Request $request): JsonResponse
    {
        $query = $request->query('q', '');
        $user = auth()->user();

        if (strlen(trim($query)) < 1) {
            return response()->json([
                'message' => 'Veuillez entrer au moins un caractère.',
                'data' => [],
            ]);
        }

        $query = trim($query);
        $searchTerm = "%{$query}%";

        $members = User::where('id', '!=', $user->id)
            ->where(function ($q) use ($searchTerm) {
                $q->whereRaw('LOWER(prenom) LIKE LOWER(?)', [$searchTerm])
                  ->orWhereRaw('LOWER(nom) LIKE LOWER(?)', [$searchTerm])
                  ->orWhereRaw('LOWER(name) LIKE LOWER(?)', [$searchTerm])
                  ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$searchTerm]);
            })
            ->select('id', 'prenom', 'nom', 'name', 'email', 'role')
            ->limit(20)
            ->get();

        return response()->json([
            'message' => 'Résultats de recherche.',
            'data' => $members,
        ]);
    }

    public function startIndividualConversation(User $member): JsonResponse
    {
        $user = auth()->user();

        if ($member->id === $user->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas créer une conversation avec vous-même.',
            ], 400);
        }

        $existingConversation = Conversation::where('type', 'individuel')
            ->where('creator_id', $user->id)
            ->whereHas('participants', function ($query) use ($member) {
                $query->where('user_id', $member->id);
            })
            ->first();

        if ($existingConversation) {
            return response()->json([
                'message' => 'Conversation existante récupérée.',
                'data' => $existingConversation,
            ]);
        }

        $conversation = Conversation::create([
            'type' => 'individuel',
            'creator_id' => $user->id,
        ]);

        $conversation->participants()->attach([$user->id, $member->id]);
        $conversation->load(['participants', 'messages']);

        return response()->json([
            'message' => 'Conversation créée avec succès.',
            'data' => $conversation,
        ], 201);
    }

    public function sendBroadcastMessage(BroadcastMessageRequest $request): JsonResponse
    {
        $user = auth()->user();
        $memberIds = $request->validated()['member_ids'] ?? [];

        if (empty($memberIds)) {
            return response()->json([
                'message' => 'Veuillez sélectionner au moins un destinataire.',
            ], 400);
        }

        $conversation = Conversation::create([
            'type' => 'broadcast',
            'creator_id' => $user->id,
            'name' => null,
        ]);

        $conversation->participants()->attach(array_merge([$user->id], $memberIds));

        $messages = [];
        foreach ($memberIds as $memberId) {
            if ($memberId != $user->id) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'receiver_id' => $memberId,
                    'content' => $request->validated()['content'],
                    'is_read' => false,
                ]);
                $messages[] = $message;
            }
        }

        return response()->json([
            'message' => 'Message de diffusion envoyé avec succès.',
            'data' => [
                'conversation_id' => $conversation->id,
                'messages' => $messages,
            ],
        ], 201);
    }

    public function getReceivedMessages(): JsonResponse
    {
        $user = auth()->user();

        $messages = Message::where('receiver_id', $user->id)
            ->where('is_read', false)
            ->with(['sender', 'conversation'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Vos messages reçus.',
            'data' => $messages,
        ]);
    }

    public function markMessageAsRead($messageId): JsonResponse
    {
        $user = auth()->user();
        $message = Message::findOrFail($messageId);

        if ($message->receiver_id !== $user->id && $message->user_id !== $user->id) {
            return response()->json([
                'message' => 'Vous n\'avez pas accès à ce message.',
            ], 403);
        }

        $message->update(['is_read' => true]);

        return response()->json([
            'message' => 'Message marqué comme lu.',
            'data' => $message,
        ]);
    }

    public function userMessages(): JsonResponse
    {
        $user = auth()->user();

        $messages = Message::whereHas('club', function ($query) use ($user) {
            $query->whereHas('membres', function ($membersQuery) use ($user) {
                $membersQuery->where('user_id', $user->id);
            })->orWhere('createur_id', $user->id);
        })
            ->with('sender', 'club')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Vos messages.',
            'data' => $messages,
        ]);
    }

    public function index(Club $club): JsonResponse
    {
        $isMember = $club->membres()->where('user_id', auth()->id())->exists() ||
                    $club->createur_id === auth()->id();

        if (!$isMember) {
            return response()->json([
                'message' => 'Vous n\'avez pas accès aux messages de ce club.',
            ], 403);
        }

        $messages = $club->messages()
            ->with('sender')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'message' => 'Messages du club.',
            'data' => $messages,
        ]);
    }

    public function store(StoreMessageRequest $request, Club $club): JsonResponse
    {
        if ($club->createur_id !== auth()->id()) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à envoyer des messages dans ce club.',
            ], 403);
        }

        $message = Message::create([
            'club_id' => $club->id,
            'user_id' => auth()->id(),
            'content' => $request->validated()['content'],
        ]);

        $message->load('sender');

        foreach ($club->membres as $member) {
            if ($member->id !== auth()->id()) {
                $member->notify(new NouveauMessageNotification($message, $club));
            }
        }

        return response()->json([
            'message' => 'Message envoyé avec succès.',
            'data' => $message,
        ], 201);
    }
}
