<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\ApartmentMember;
use App\Models\FacultyAffinityGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Mirrors Sukoon apartments (and member affinity data) into recommender/recommender.db
 * so Python ranking uses real listing IDs (UUIDs) and live occupancy.
 */
class RecommenderDatabaseSync
{
    public function dbPath(): string
    {
        return dirname(base_path()) . DIRECTORY_SEPARATOR . 'recommender' . DIRECTORY_SEPARATOR . 'recommender.db';
    }

    public function sync(): bool
    {
        $path = $this->dbPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            Log::warning('RecommenderDatabaseSync: recommender directory missing', ['path' => $dir]);
            return false;
        }

        try {
            if (file_exists($path) && !$this->usesTextApartmentIds($path)) {
                unlink($path);
                Log::info('RecommenderDatabaseSync: removed legacy integer-id database');
            }
            $this->ensureSchema($path);
            $pdo = $this->connect($path);
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec('DELETE FROM apartment_members');
            $pdo->exec('DELETE FROM student_details');
            $pdo->exec('DELETE FROM apartments');
            $pdo->exec('DELETE FROM users');
            $pdo->exec('DELETE FROM faculties');
            $pdo->exec('PRAGMA foreign_keys = ON');

            $this->seedModelConfig($pdo);
            $this->syncAffinityMatrix($pdo);
            $this->syncFaculties($pdo);
            $aptCount = $this->syncApartments($pdo);
            $memberCount = $this->syncMembers($pdo);

            Log::info('RecommenderDatabaseSync complete', [
                'apartments' => $aptCount,
                'members' => $memberCount,
                'db' => $path,
            ]);

            return $aptCount > 0;
        } catch (\Throwable $e) {
            Log::error('RecommenderDatabaseSync failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    private function usesTextApartmentIds(string $path): bool
    {
        try {
            $pdo = $this->connect($path);
            $stmt = $pdo->query("PRAGMA table_info(apartments)");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (($row['name'] ?? '') === 'id') {
                    $type = strtoupper((string) ($row['type'] ?? ''));
                    return str_contains($type, 'TEXT') || str_contains($type, 'CHAR');
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function ensureSchema(string $path): void
    {
        $create = !file_exists($path);
        if ($create) {
            $pdo = $this->connect($path);
            $schemaFile = database_path('recommender_sqlite_schema.sql');
            $sql = file_exists($schemaFile)
                ? file_get_contents($schemaFile)
                : $this->inlineSchema();
            $pdo->exec($sql);
        }

        $this->migrateSchema($this->connect($path));
    }

    /**
     * Add columns introduced after the first recommender.db release.
     */
    private function migrateSchema(PDO $pdo): void
    {
        $this->ensureColumn($pdo, 'apartments', 'latitude', 'REAL');
        $this->ensureColumn($pdo, 'apartments', 'longitude', 'REAL');
    }

    private function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array($column, $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            Log::info('RecommenderDatabaseSync: added column', compact('table', 'column'));
        }
    }

    private function inlineSchema(): string
    {
        return file_get_contents(database_path('recommender_sqlite_schema.sql')) ?: '';
    }

    private function seedModelConfig(PDO $pdo): void
    {
        $weights = [
            ['price', 0.30],
            ['distance', 0.18],
            ['readiness', 0.16],
            ['academic', 0.15],
            ['location', 0.10],
            ['furnished', 0.06],
            ['entropy', 0.05],
        ];
        $stmt = $pdo->prepare(
            'INSERT OR REPLACE INTO model_config_weights("key", weight, active) VALUES (?, ?, 1)'
        );
        foreach ($weights as [$key, $weight]) {
            $stmt->execute([$key, $weight]);
        }
    }

    private function syncAffinityMatrix(PDO $pdo): void
    {
        $pdo->exec('DELETE FROM affinity_similarity');
        $rows = DB::table('affinity_similarity_matrix')->get();
        $stmt = $pdo->prepare(
            'INSERT OR REPLACE INTO affinity_similarity(src_group, dst_group, similarity) VALUES (?, ?, ?)'
        );
        foreach ($rows as $row) {
            $stmt->execute([$row->source_group, $row->target_group, (float) $row->similarity_score]);
        }
    }

    private function syncFaculties(PDO $pdo): void
    {
        $stmt = $pdo->prepare('INSERT INTO faculties(name, affinity_group) VALUES (?, ?)');
        foreach (FacultyAffinityGroup::all() as $faculty) {
            $stmt->execute([$faculty->faculty_name, $faculty->affinity_group]);
        }
    }

    private function facultyIdFor(PDO $pdo, ?string $facultyName): ?int
    {
        if (!$facultyName) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM faculties WHERE name = ? LIMIT 1');
        $stmt->execute([$facultyName]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function syncApartments(PDO $pdo): int
    {
        $apartments = Apartment::query()
            ->where('status', 'open')
            ->where('verification_status', 'approved')
            ->get();

        $stmt = $pdo->prepare(
            'INSERT INTO apartments(id, title, description, price, location, latitude, longitude, is_furnished, capacity, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $count = 0;
        foreach ($apartments as $apt) {
            $location = $apt->location_label ?? 'Cairo';

            $stmt->execute([
                (string) $apt->id,
                $location,
                '',
                (float) $apt->price,
                $location,
                $apt->latitude !== null ? (float) $apt->latitude : null,
                $apt->longitude !== null ? (float) $apt->longitude : null,
                $apt->is_furnished ? 1 : 0,
                (int) $apt->capacity ?: 1,
                'AVAILABLE',
            ]);
            $count++;
        }

        return $count;
    }

    private function syncMembers(PDO $pdo): int
    {
        $aptIds = $pdo->query('SELECT id FROM apartments')->fetchAll(PDO::FETCH_COLUMN);
        $aptIdSet = array_flip(array_map('strval', $aptIds));

        $members = ApartmentMember::query()
            ->where('membership_status', 'active')
            ->with(['user.profile', 'user.rentalProfile.studentDetails.program'])
            ->get();

        $userStmt = $pdo->prepare('INSERT OR IGNORE INTO users(id, name, email) VALUES (?, ?, ?)');
        $sdStmt = $pdo->prepare(
            'INSERT OR REPLACE INTO student_details(user_id, faculty_id) VALUES (?, ?)'
        );
        $memStmt = $pdo->prepare(
            'INSERT INTO apartment_members(apartment_id, user_id, status) VALUES (?, ?, ?)'
        );

        $count = 0;
        foreach ($members as $member) {
            $aptId = (string) $member->apartment_id;
            if (!isset($aptIdSet[$aptId])) {
                continue;
            }

            $user = $member->user;
            if (!$user) {
                continue;
            }

            $userStmt->execute([
                (string) $user->id,
                $user->profile?->first_name ?? 'Tenant',
                $user->email ?? '',
            ]);

            $student = $user->rentalProfile?->studentDetails;
            $facultyName = $student?->program?->name ?? $student?->faculty;
            $facultyId = $this->facultyIdFor($pdo, $facultyName);
            if ($facultyId) {
                $sdStmt->execute([(string) $user->id, $facultyId]);
            }

            $memStmt->execute([$aptId, (string) $user->id, 'ACTIVE']);
            $count++;
        }

        return $count;
    }
}
