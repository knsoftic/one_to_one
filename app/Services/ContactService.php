<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Matches a user's phone-book entries against registered accounts, like
 * WhatsApp. Only matches are stored; other numbers are discarded.
 */
class ContactService
{
    /**
     * @return Collection<int, Contact>
     */
    public function listFor(User $user): Collection
    {
        return $user->contacts()
            ->with('contactUser')
            ->whereHas('contactUser', fn ($q) => $q->active())
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  list<array{name?: ?string, phones: list<string>}>  $entries
     * @return array{matched: Collection<int, Contact>, checked: int}
     */
    public function sync(User $user, array $entries): array
    {
        // suffix => [[digits, name, formatted], ...]
        $bySuffix = [];
        $checked = 0;

        foreach ($entries as $entry) {
            $name = $this->cleanName($entry['name'] ?? '');

            foreach (array_slice($entry['phones'] ?? [], 0, 5) as $phone) {
                $suffix = Phone::suffix((string) $phone);
                if ($suffix === null) {
                    continue;
                }
                $checked++;
                $bySuffix[$suffix][] = ['phone' => Phone::normalize((string) $phone), 'name' => $name];
            }
        }

        if ($bySuffix === []) {
            return ['matched' => new Collection, 'checked' => $checked];
        }

        $candidates = collect(array_keys($bySuffix))
            ->chunk(500)
            ->flatMap(fn ($suffixes) => User::query()
                ->active()
                ->whereKeyNot($user->getKey())
                ->whereIn('phone_suffix', $suffixes->all())
                ->get(['id', 'name', 'phone', 'phone_suffix']));

        $matchedIds = [];

        foreach ($candidates as $candidate) {
            foreach ($bySuffix[$candidate->phone_suffix] ?? [] as $entry) {
                if (! Phone::matches($entry['phone'], $candidate->phone)) {
                    continue;
                }

                Contact::query()->updateOrCreate(
                    ['user_id' => $user->getKey(), 'contact_user_id' => $candidate->id],
                    ['name' => $entry['name'] ?: $candidate->name, 'phone' => $entry['phone']],
                );
                $matchedIds[] = $candidate->id;
                break;
            }
        }

        $matched = $user->contacts()
            ->with('contactUser')
            ->whereIn('contact_user_id', array_unique($matchedIds))
            ->orderBy('name')
            ->get();

        return ['matched' => $matched, 'checked' => $checked];
    }

    /**
     * Saved names keyed by contact user id (for showing phone-book names in chats).
     *
     * @param  iterable<int>  $userIds
     * @return array<int, string>
     */
    public function savedNames(User $user, iterable $userIds): array
    {
        $ids = collect($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return $user->contacts()
            ->whereIn('contact_user_id', $ids)
            ->pluck('name', 'contact_user_id')
            ->all();
    }

    private function cleanName(?string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', (string) $name) ?? '';

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 100, '');
    }
}
