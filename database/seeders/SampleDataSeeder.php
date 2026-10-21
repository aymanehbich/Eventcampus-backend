<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

/**
 * Realistic example data for every application table.
 *
 *   php artisan db:seed --class=SampleDataSeeder
 *
 * Additive and idempotent: nothing is deleted or overwritten, and running it twice does not
 * create duplicates (rows are matched on a natural key: e-mail, club name, event title…).
 * All sample accounts use the password "password" (admin: "admin123").
 *
 * Framework tables (cache, cache_locks, jobs, job_batches, failed_jobs, sessions,
 * personal_access_tokens, password_reset_tokens, migrations) are runtime data and are left alone.
 */
class SampleDataSeeder extends Seeder
{
    private $now;

    public function run(): void
    {
        $this->now = now();

        DB::transaction(function () {
            $users = $this->users();
            $this->categories();
            $clubs = $this->clubs($users);
            $this->memberships($users, $clubs);
            $events = $this->events($users, $clubs);
            $this->registrations($users, $events);
            $this->conversations($users, $clubs);
            $this->notifications($users, $clubs, $events);
            $this->roleRequests($users);
        });

        $this->command?->info('Sample data ready. Login examples: admin@eventcampus.com / admin123, sara.benali@univ.ma (organisateur) and aymane.hbich@univ.ma (étudiant) / password');
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Insert unless a row matching $where exists; returns the id either way. */
    private function once(string $table, array $where, array $values = []): int
    {
        $id = DB::table($table)->where($where)->value('id');
        if ($id) {
            return (int) $id;
        }
        return (int) DB::table($table)->insertGetId(array_merge($where, $values, [
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]));
    }

    private function days(int $offset): string
    {
        return $this->now->copy()->addDays($offset)->toDateString();
    }

    // ───────────────────────── users ─────────────────────────

    private function users(): array
    {
        $rows = [
            // key => [prenom, nom, email, role, phone]
            'admin'    => ['Admin', 'EventCampus', 'admin@eventcampus.com', 'admin', null],
            'sara'     => ['Sara', 'Benali', 'sara.benali@univ.ma', 'organisateur', '+212661000001'],
            'ahmad'    => ['Ahmad', 'Aarab', 'ahmad.aarab@univ.ma', 'organisateur', '+212661000002'],
            'nadia'    => ['Nadia', 'Kabbaj', 'nadia.kabbaj@univ.ma', 'organisateur', null],
            'aymane'   => ['Aymane', 'Hbich', 'aymane.hbich@univ.ma', 'etudiant', '+212661000011'],
            'salma'    => ['Salma', 'Zahraoui', 'salma.zahraoui@univ.ma', 'etudiant', null],
            'youssef'  => ['Youssef', 'El Amrani', 'youssef.elamrani@univ.ma', 'etudiant', '+212661000013'],
            'imane'    => ['Imane', 'Bouzid', 'imane.bouzid@univ.ma', 'etudiant', null],
            'omar'     => ['Omar', 'Tazi', 'omar.tazi@univ.ma', 'etudiant', null],
            'khadija'  => ['Khadija', 'Ouali', 'khadija.ouali@univ.ma', 'etudiant', '+212661000016'],
            'reda'     => ['Reda', 'Chraibi', 'reda.chraibi@univ.ma', 'etudiant', null],
            'zineb'    => ['Zineb', 'Alaoui', 'zineb.alaoui@univ.ma', 'etudiant', null],
            'banni'    => ['Karim', 'Banni', 'karim.banni@univ.ma', 'banni', null],
        ];

        $users = [];
        foreach ($rows as $key => [$prenom, $nom, $email, $role, $phone]) {
            $users[$key] = $this->once('users', ['email' => $email], [
                'prenom' => $prenom,
                'nom' => $nom,
                'name' => "$prenom $nom",
                'phone' => $phone,
                'role' => $role,
                'password' => Hash::make($key === 'admin' ? 'admin123' : 'password'),
                'email_reminders' => $key !== 'omar',
                'whatsapp_reminders' => in_array($key, ['aymane', 'khadija'], true),
                'email_verified_at' => $this->now,
            ]);
        }

        // An account that already exists (e.g. registered by hand) takes part in the sample data too.
        $existing = DB::table('users')->where('email', 'test1@gmail.com')->value('id');
        if ($existing) {
            $users['existing'] = (int) $existing;
        }

        return $users;
    }

    private function categories(): void
    {
        foreach ([
            ['Conférence', '🎤'], ['Atelier', '🎨'], ['Soirée', '🎭'], ['Réunion de club', '👥'],
            ['Hackathon', '👨‍💻'], ['Environnement', '🌱'], ['Sport', '⚽'], ['Culture', '🎭'],
        ] as [$nom, $emoji]) {
            $this->once('categories', ['nom' => $nom], ['emoji' => $emoji]);
        }
    }

    // ───────────────────────── clubs ─────────────────────────

    private function clubs(array $u): array
    {
        $rows = [
            // key => [nom, categorie, emoji, statut, créateur, description]
            'tech'     => ['Club Tech & Innovation', 'Technologie', '💻', 'validé', 'sara',
                'Hackathons, ateliers de programmation et conférences sur l\'IA et le développement web.'],
            'robot'    => ['Club Robotique FSSM', 'Technologie', '🤖', 'validé', 'ahmad',
                'Conception de robots, systèmes embarqués et participation aux compétitions nationales.'],
            'theatre'  => ['Club Théâtre & Expression', 'Culture', '🎭', 'validé', 'nadia',
                'Improvisation, mise en scène et représentations ouvertes à toute la faculté.'],
            'env'      => ['Club Environnement', 'Environnement', '🌱', 'validé', 'sara',
                'Actions de nettoyage du campus, sensibilisation au climat et jardin partagé.'],
            'astro'    => ['Club Astronomie', 'Culture', '🔭', 'validé', 'ahmad',
                'Soirées d\'observation du ciel, initiation à l\'astrophotographie.'],
            'photo'    => ['Club Photographie', 'Culture', '📷', 'en_attente', 'nadia',
                'Sorties photo, ateliers de composition et expositions étudiantes. (en attente de validation)'],
            'echecs'   => ['Club Échecs & E-sport', 'Sport', '♟️', 'suspendu', 'ahmad',
                'Tournois inter-facultés d\'échecs et de jeux vidéo. (suspendu pour inactivité)'],
            'debat'    => ['Club Débat', 'Culture', '🗣️', 'rejeté', 'nadia',
                'Joutes oratoires et préparation aux concours d\'éloquence. (rejeté : description incomplète)'],
        ];

        $clubs = [];
        foreach ($rows as $key => [$nom, $cat, $emoji, $statut, $owner, $desc]) {
            $clubs[$key] = $this->once('clubs', ['nom' => $nom], [
                'description' => $desc,
                'categorie' => $cat,
                'emoji' => $emoji,
                'statut' => $statut,
                'createur_id' => $u[$owner],
            ]);
        }
        return $clubs;
    }

    private function memberships(array $u, array $c): void
    {
        // club => [responsable, members…]
        $members = [
            'tech'    => ['sara', 'aymane', 'salma', 'youssef', 'imane', 'existing'],
            'robot'   => ['ahmad', 'omar', 'reda', 'aymane'],
            'theatre' => ['nadia', 'khadija', 'zineb', 'salma'],
            'env'     => ['sara', 'imane', 'zineb', 'existing'],
            'astro'   => ['ahmad', 'reda', 'omar'],
            'echecs'  => ['ahmad', 'youssef'],
        ];

        foreach ($members as $club => $list) {
            foreach ($list as $i => $key) {
                if (!isset($u[$key])) {
                    continue;
                }
                $exists = DB::table('club_user')->where(['club_id' => $c[$club], 'user_id' => $u[$key]])->exists();
                if (!$exists) {
                    DB::table('club_user')->insert([
                        'club_id' => $c[$club], 'user_id' => $u[$key],
                        'role' => $i === 0 ? 'responsable' : 'membre',
                        'created_at' => $this->now->copy()->subDays(30 - $i), 'updated_at' => $this->now,
                    ]);
                }
                if ($i > 0) {
                    $this->once('adhesions', ['user_id' => $u[$key], 'club_id' => $c[$club]], ['statut' => 'accepté']);
                }
            }
        }

        // pending and refused join requests
        foreach ([
            ['omar', 'tech', 'en_attente'], ['zineb', 'tech', 'en_attente'], ['khadija', 'env', 'en_attente'],
            ['reda', 'theatre', 'refusé'], ['imane', 'robot', 'en_attente'],
        ] as [$user, $club, $statut]) {
            $this->once('adhesions', ['user_id' => $u[$user], 'club_id' => $c[$club]], ['statut' => $statut]);
        }
    }

    // ───────────────────────── events ─────────────────────────

    private function events(array $u, array $c): array
    {
        $rows = [
            // key => [titre, description, jours (offset), heure, durée h, lieu, catégorie, club, capacité, statut]
            'ia_past'     => ['Conférence IA et Machine Learning', 'Panorama de l\'IA moderne par des chercheurs et des ingénieurs.', -40, '14:00', 3, 'Amphi Ibn Battouta', 'Conférence', 'tech', 150, 'Validé'],
            'hack_past'   => ['Hackathon 24h GreenTech', 'Équipes de 4, 24 heures pour concevoir une solution IoT durable.', -25, '09:00', 24, 'Salle Informatique B2', 'Hackathon', 'tech', 60, 'Accepté'],
            'clean_past'  => ['Nettoyage du Campus et Zone Verte', 'Matinée citoyenne : gants et sacs fournis.', -10, '09:30', 3, 'Parvis de la faculté', 'Environnement', 'env', 80, 'Validé'],
            'robot_ws'    => ['Atelier Robotique & Systèmes Embarqués', 'Initiation à Arduino et capteurs, matériel fourni.', 3, '15:00', 3, 'Salle C1', 'Atelier', 'robot', 20, 'Validé'],
            'theatre_sh'  => ['Représentation : La Métamorphose', 'Adaptation théâtrale de Kafka par le club.', 7, '20:00', 2, 'Centre Culturel UCA', 'Soirée', 'theatre', 120, 'Validé'],
            'night_sky'   => ['Soirée d\'observation du ciel', 'Télescopes à disposition, météo permitting.', 10, '21:30', 3, 'Terrasse du bloc D', 'Soirée', 'astro', 40, 'Validé'],
            'react_ws'    => ['Atelier Web Development avec React', 'Construisez une petite application de A à Z.', 14, '10:00', 4, 'Salle Informatique B1', 'Atelier', 'tech', 3, 'Validé'],
            'climat'      => ['Conférence : Changement climatique au Maroc', 'Eau, agriculture et adaptation, avec table ronde.', 21, '16:00', 2, 'Auditorium Al-Idrissi', 'Conférence', 'env', 200, 'Accepté'],
            'hack_next'   => ['Semlalia Hackathon 2026', 'Grand hackathon annuel : prix de 50 000 MAD.', 30, '09:00', 30, 'Amphi Ibn Battouta', 'Hackathon', 'tech', 100, 'Validé'],
            'impro'       => ['Atelier d\'improvisation théâtrale', 'Débutants bienvenus.', 45, '17:00', 2, 'Salle Omnisports', 'Atelier', 'theatre', 25, 'Validé'],
            'photo_pend'  => ['Exposition photo étudiante', 'En attente de validation par l\'administration.', 20, '10:00', 6, 'Hall principal', 'Culture', 'theatre', 150, 'En attente'],
            'chess_pend'  => ['Tournoi d\'échecs inter-facultés', 'En attente de validation par l\'administration.', 25, '14:00', 5, 'Salle Omnisports', 'Sport', 'robot', 64, 'En attente'],
            'party_rej'   => ['Soirée de fin d\'année', 'Rejetée : date en conflit avec les examens.', 12, '22:00', 4, 'Cafétéria', 'Soirée', 'astro', 80, 'Rejeté'],
        ];

        $events = [];
        foreach ($rows as $key => [$titre, $desc, $offset, $heure, $dur, $lieu, $cat, $club, $cap, $statut]) {
            $start = $this->now->copy()->addDays($offset)->setTimeFromTimeString($heure);
            $end = $start->copy()->addHours($dur);
            $owner = DB::table('clubs')->where('id', $c[$club])->value('createur_id');
            $events[$key] = $this->once('events', ['titre' => $titre, 'club_id' => $c[$club]], [
                'description' => $desc,
                'date' => $start->toDateString(),
                'heure' => $start->format('H:i:s'),
                'date_fin' => $end->toDateString(),
                'heure_fin' => $end->format('H:i:s'),
                'lieu' => $lieu,
                'categorie' => $cat,
                'capacite_max' => $cap,
                'places_disponibles' => $cap,
                'statut' => $statut,
                'user_id' => $owner,
            ]);
        }
        return $events;
    }

    // ───────────────────────── registrations / waitlist ─────────────────────────

    private function registrations(array $u, array $e): void
    {
        // event => students registered (calendar_added for the first one)
        $regs = [
            'ia_past'    => ['aymane', 'salma', 'youssef', 'imane', 'existing'],
            'hack_past'  => ['aymane', 'omar', 'reda', 'youssef'],
            'clean_past' => ['imane', 'zineb', 'existing'],
            'robot_ws'   => ['omar', 'reda', 'aymane'],
            'theatre_sh' => ['khadija', 'zineb', 'salma', 'aymane'],
            'night_sky'  => ['reda', 'omar'],
            'react_ws'   => ['aymane', 'salma', 'youssef'],           // capacity 3 -> full
            'climat'     => ['imane', 'zineb', 'existing'],
            'hack_next'  => ['aymane', 'youssef', 'omar', 'reda', 'salma'],
        ];

        foreach ($regs as $event => $keys) {
            $added = 0;
            foreach ($keys as $i => $key) {
                if (!isset($u[$key])) {
                    continue;
                }
                $exists = DB::table('event_user')->where(['event_id' => $e[$event], 'user_id' => $u[$key]])->exists();
                if (!$exists) {
                    DB::table('event_user')->insert([
                        'event_id' => $e[$event], 'user_id' => $u[$key],
                        'calendar_added' => $i === 0,
                        'created_at' => $this->now->copy()->subDays(8 - $i), 'updated_at' => $this->now,
                    ]);
                }
                $added++;
            }
            // keep the counter consistent with the real number of registrations
            $count = DB::table('event_user')->where('event_id', $e[$event])->count();
            $cap = DB::table('events')->where('id', $e[$event])->value('capacite_max');
            DB::table('events')->where('id', $e[$event])->update(['places_disponibles' => max(0, $cap - $count)]);
        }

        // waitlist on the full workshop: waiting, promoted, cancelled
        foreach ([
            ['omar', 1, 'en_attente', null], ['reda', 2, 'en_attente', null],
            ['imane', 3, 'promu', $this->now->copy()->subDay()], ['zineb', 4, 'annule', null],
        ] as [$user, $pos, $statut, $notified]) {
            $this->once('event_waitlists', ['event_id' => $e['react_ws'], 'user_id' => $u[$user]], [
                'position' => $pos, 'statut' => $statut, 'notifie_at' => $notified,
            ]);
        }

        // legacy placeholder table `event_users` (id + timestamps only)
        if (DB::table('event_users')->count() === 0) {
            for ($i = 0; $i < 3; $i++) {
                DB::table('event_users')->insert(['created_at' => $this->now, 'updated_at' => $this->now]);
            }
        }
    }

    // ───────────────────────── conversations / messages ─────────────────────────

    private function conversations(array $u, array $c): void
    {
        Storage::disk('public')->put('message_attachments/programme-hackathon.txt',
            "Semlalia Hackathon 2026 - programme\n09:00 Accueil\n10:00 Lancement du challenge\n18:00 Point d'étape\n");

        // [student, organiser, [[sender, text, minutesAgo, attachment?]...]]
        $threads = [
            ['aymane', 'sara', [
                ['aymane', 'Bonjour, est-ce que le hackathon est ouvert aux étudiants de L2 ?', 600],
                ['sara', 'Bonjour Aymane, oui bien sûr ! Les équipes de 4 sont mixtes, toutes les années.', 580],
                ['sara', 'Voici le programme prévisionnel de la journée.', 575, 'programme-hackathon.txt'],
                ['aymane', 'Parfait, merci beaucoup, on s\'inscrit ce soir.', 40],
            ]],
            ['salma', 'nadia', [
                ['salma', 'Bonsoir, y a-t-il encore des places pour la représentation de La Métamorphose ?', 300],
                ['nadia', 'Oui, il reste de la place. Inscrivez-vous depuis la page de l\'événement.', 120],
            ]],
            ['omar', 'ahmad', [
                ['omar', 'Salut, je peux rejoindre le club Robotique en cours d\'année ?', 90],
            ]],
        ];

        foreach ($threads as [$student, $org, $msgs]) {
            $conv = $this->conversationBetween($u[$student], $u[$org]);
            foreach ($msgs as $m) {
                $sender = $u[$m[0]];
                $receiver = $m[0] === $student ? $u[$org] : $u[$student];
                $attachment = $m[3] ?? null;
                $when = $this->now->copy()->subMinutes($m[2]);
                $exists = DB::table('messages')->where(['conversation_id' => $conv, 'user_id' => $sender, 'content' => $m[1]])->exists();
                if (!$exists) {
                    DB::table('messages')->insert([
                        'conversation_id' => $conv, 'club_id' => null,
                        'user_id' => $sender, 'receiver_id' => $receiver,
                        'content' => $m[1], 'is_read' => $m[2] > 60,
                        'attachment_path' => $attachment ? "message_attachments/$attachment" : null,
                        'attachment_name' => $attachment,
                        'created_at' => $when, 'updated_at' => $when,
                    ]);
                }
            }
            DB::table('conversations')->where('id', $conv)->update(['updated_at' => $this->now]);
        }

        // broadcast from the Tech club organiser to all its members
        $broadcast = $this->once('conversations', ['type' => 'broadcast', 'creator_id' => $u['sara'], 'name' => 'Annonces Club Tech & Innovation']);
        foreach (['sara', 'aymane', 'salma', 'youssef', 'imane'] as $key) {
            $this->pivot('conversation_user', ['conversation_id' => $broadcast, 'user_id' => $u[$key]]);
        }
        foreach ([
            ['Rappel : l\'atelier React a lieu dans 14 jours, il ne reste plus de places mais la liste d\'attente est ouverte.', 2000],
            ['Les inscriptions au Semlalia Hackathon 2026 sont ouvertes !', 200],
        ] as [$text, $ago]) {
            foreach (['aymane', 'salma', 'youssef', 'imane'] as $key) {
                $exists = DB::table('messages')->where(['conversation_id' => $broadcast, 'receiver_id' => $u[$key], 'content' => $text])->exists();
                if (!$exists) {
                    $when = $this->now->copy()->subMinutes($ago);
                    DB::table('messages')->insert([
                        'conversation_id' => $broadcast, 'club_id' => $c['tech'], 'user_id' => $u['sara'],
                        'receiver_id' => $u[$key], 'content' => $text, 'is_read' => $ago > 1000,
                        'created_at' => $when, 'updated_at' => $when,
                    ]);
                }
            }
        }
    }

    private function conversationBetween(int $a, int $b): int
    {
        $found = DB::table('conversations')
            ->join('conversation_user as x', 'x.conversation_id', '=', 'conversations.id')
            ->join('conversation_user as y', 'y.conversation_id', '=', 'conversations.id')
            ->where('conversations.type', 'individuel')
            ->where('x.user_id', $a)->where('y.user_id', $b)
            ->value('conversations.id');
        if ($found) {
            return (int) $found;
        }
        $id = (int) DB::table('conversations')->insertGetId([
            'type' => 'individuel', 'creator_id' => $a, 'name' => null,
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $this->pivot('conversation_user', ['conversation_id' => $id, 'user_id' => $a]);
        $this->pivot('conversation_user', ['conversation_id' => $id, 'user_id' => $b]);
        return $id;
    }

    private function pivot(string $table, array $keys): void
    {
        if (!DB::table($table)->where($keys)->exists()) {
            DB::table($table)->insert($keys + ['created_at' => $this->now, 'updated_at' => $this->now]);
        }
    }

    // ───────────────────────── notifications (database channel) ─────────────────────────

    private function notifications(array $u, array $c, array $e): void
    {
        $title = fn (string $key) => DB::table('events')->where('id', $e[$key])->value('titre');
        $club = fn (string $key) => DB::table('clubs')->where('id', $c[$key])->value('nom');
        $name = fn (string $key) => DB::table('users')->where('id', $u[$key])->value('name');

        $items = [
            // [destinataire, classe, données, lue ?]
            ['sara', 'NouvelleInscriptionNotification', ['event_id' => $e['react_ws'], 'event_titre' => $title('react_ws'), 'etudiant_id' => $u['aymane'], 'etudiant' => $name('aymane'), 'message' => $name('aymane') . " s'est inscrit à votre événement \"" . $title('react_ws') . '".'], false],
            ['sara', 'NouvelleAdhesionNotification', ['club_id' => $c['tech'], 'club_nom' => $club('tech'), 'etudiant_id' => $u['omar'], 'etudiant' => $name('omar'), 'message' => $name('omar') . " a demandé à rejoindre votre club \"" . $club('tech') . '".'], false],
            ['sara', 'NouvelleInscriptionNotification', ['event_id' => $e['hack_next'], 'event_titre' => $title('hack_next'), 'etudiant_id' => $u['salma'], 'etudiant' => $name('salma'), 'message' => $name('salma') . " s'est inscrit à votre événement \"" . $title('hack_next') . '".'], true],
            ['ahmad', 'EvenementModifieNotification', ['event_id' => $e['chess_pend'], 'event_titre' => $title('chess_pend'), 'message' => "L'événement \"" . $title('chess_pend') . "\" a été modifié et est en attente d'approbation."], false],
            ['aymane', 'AdhesionAccepteeNotification', ['club_id' => $c['tech'], 'club_nom' => $club('tech'), 'message' => "Votre demande d'adhésion au club \"" . $club('tech') . '" a été acceptée.'], true],
            ['aymane', 'NouveauMessageNotification', ['club_id' => $c['tech'], 'club_nom' => $club('tech'), 'sender_id' => $u['sara'], 'sender_name' => $name('sara'), 'content' => 'Les inscriptions au Semlalia Hackathon 2026 sont ouvertes !', 'message' => $name('sara') . ' a envoyé un message dans ' . $club('tech') . '.'], false],
            ['imane', 'PlaceDisponibleNotification', ['type' => 'place_disponible', 'event_id' => $e['react_ws'], 'event_titre' => $title('react_ws'), 'message' => "Une place s'est libérée pour \"" . $title('react_ws') . '".'], false],
            ['salma', 'AdhesionAccepteeNotification', ['club_id' => $c['theatre'], 'club_nom' => $club('theatre'), 'message' => "Votre demande d'adhésion au club \"" . $club('theatre') . '" a été acceptée.'], true],
        ];

        foreach ($items as $i => [$who, $class, $data, $read]) {
            $id = Uuid::uuid5(Uuid::NAMESPACE_DNS, "eventcampus-sample-notification-$i")->toString();
            if (DB::table('notifications')->where('id', $id)->exists()) {
                continue;
            }
            $when = $this->now->copy()->subHours(($i + 1) * 5);
            DB::table('notifications')->insert([
                'id' => $id,
                'type' => 'App\\Notifications\\' . $class,
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id' => $u[$who],
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'read_at' => $read ? $when->copy()->addHour() : null,
                'created_at' => $when, 'updated_at' => $when,
            ]);
        }
    }

    // ───────────────────────── role requests ─────────────────────────

    private function roleRequests(array $u): void
    {
        $this->once('role_requests', ['email' => 'houda.mansouri@univ.ma'], [
            'user_id' => null, 'name' => 'Houda Mansouri', 'student_id' => '20231045',
            'is_existing_student' => false, 'status' => 'pending',
        ]);
        $this->once('role_requests', ['email' => 'youssef.elamrani@univ.ma'], [
            'user_id' => $u['youssef'], 'name' => 'Youssef El Amrani', 'student_id' => '20221187',
            'is_existing_student' => true, 'status' => 'pending',
        ]);
        $this->once('role_requests', ['email' => 'sara.benali@univ.ma'], [
            'user_id' => $u['sara'], 'name' => 'Sara Benali', 'student_id' => '20210932',
            'is_existing_student' => true, 'status' => 'approved',
        ]);
        $this->once('role_requests', ['email' => 'karim.banni@univ.ma'], [
            'user_id' => $u['banni'], 'name' => 'Karim Banni', 'student_id' => null,
            'is_existing_student' => true, 'status' => 'rejected',
        ]);
    }
}
