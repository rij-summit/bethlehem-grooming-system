<?php

namespace App\Services;

use App\Models\UnregisteredCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CustomerAccountDeletionService
{
    /**
     * Permanently remove account access and reusable identifiers while keeping
     * the owner row that historical pet, grooming, and clinic records reference.
     */
    public function deleteRegisteredCustomer(User $customer): void
    {
        $profilePhoto = DB::transaction(function () use ($customer): string {
            $lockedCustomer = DB::table('users')
                ->where('user_id', $customer->user_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedCustomer || $lockedCustomer->account_deleted_at !== null) {
                return '';
            }

            $originalEmail = $lockedCustomer->email ?? null;
            $originalPhone = $lockedCustomer->phone ?? null;
            $profilePhoto = Schema::hasColumn('users', 'profile_photo')
                ? trim((string) ($lockedCustomer->profile_photo ?? ''))
                : '';
            $deletedAt = now();
            $updates = [
                'email' => $this->deletedEmail('registered', (int) $customer->user_id),
                'phone' => $this->deletedPhone('registered', (int) $customer->user_id),
                'password_hash' => Hash::make(Str::random(64)),
                'is_active' => false,
                'is_archived' => true,
                'archived_at' => $deletedAt,
                'account_deleted_at' => $deletedAt,
            ];

            foreach ([
                'username',
                'profile_photo',
                'email_verified_at',
                'email_verification_token',
                'email_verification_expires_at',
            ] as $nullableColumn) {
                if (Schema::hasColumn('users', $nullableColumn)) {
                    $updates[$nullableColumn] = null;
                }
            }

            DB::table('users')->where('user_id', $customer->user_id)->update($updates);

            $this->deletePendingRegistration($originalEmail, $originalPhone);
            $this->deletePersonalAccess($customer);
            $this->deleteWhere('sessions', ['user_id' => $customer->user_id]);

            return $profilePhoto;
        });

        $this->deleteStoredProfilePhoto($profilePhoto);
    }

    /**
     * Unregistered owners have no credentials, but tombstoning their reusable
     * contacts removes the duplicate from operational customer lists while
     * preserving the row used by historical walk-ins and pets.
     */
    public function deleteUnregisteredCustomer(UnregisteredCustomer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $lockedCustomer = DB::table('unregistered_customers')
                ->where('id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedCustomer || $lockedCustomer->account_deleted_at !== null) {
                return;
            }

            $originalEmail = $lockedCustomer->email ?? null;
            $originalPhone = $lockedCustomer->phone ?? null;
            $deletedAt = now();

            DB::table('unregistered_customers')
                ->where('id', $customer->id)
                ->update([
                    'email' => $this->deletedEmail('unregistered', (int) $customer->id),
                    'phone' => $this->deletedPhone('unregistered', (int) $customer->id),
                    'is_archived' => true,
                    'archived_at' => $deletedAt,
                    'account_deleted_at' => $deletedAt,
                ]);

            $this->deletePendingRegistration($originalEmail, $originalPhone);
        });
    }

    private function deletedEmail(string $ownerType, int $id): string
    {
        return sprintf(
            'deleted-%s-%d-%s@deleted.invalid',
            $ownerType === 'registered' ? 'r' : 'u',
            $id,
            Str::lower(Str::random(12)),
        );
    }

    private function deletedPhone(string $ownerType, int $id): string
    {
        return 'd'.substr(hash('sha256', $ownerType.':'.$id.':'.Str::uuid()), 0, 18);
    }

    private function deletePendingRegistration(?string $email, ?string $phone): void
    {
        if (! Schema::hasTable('pending_customer_registrations')) {
            return;
        }

        $email = trim((string) $email);
        $phone = trim((string) $phone);
        $canMatchEmail = $email !== ''
            && Schema::hasColumn('pending_customer_registrations', 'email');
        $canMatchPhone = $phone !== ''
            && Schema::hasColumn('pending_customer_registrations', 'phone');
        if (! $canMatchEmail && ! $canMatchPhone) {
            return;
        }

        DB::table('pending_customer_registrations')
            ->where(function ($query) use ($email, $phone, $canMatchEmail, $canMatchPhone): void {
                if ($canMatchEmail) {
                    $query->where('email', $email);
                }
                if ($canMatchPhone) {
                    $canMatchEmail
                        ? $query->orWhere('phone', $phone)
                        : $query->where('phone', $phone);
                }
            })
            ->delete();
    }

    private function deletePersonalAccess(User $customer): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', $customer->getMorphClass())
            ->where('tokenable_id', $customer->user_id)
            ->delete();
    }

    private function deleteWhere(string $table, array $where): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (array_keys($where) as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        DB::table($table)->where($where)->delete();
    }

    private function deleteStoredProfilePhoto(string $path): void
    {
        if ($path === '') {
            return;
        }

        foreach (['local', 'public'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }
            } catch (Throwable $exception) {
                Log::warning('A profile photo could not be removed after account deletion.', [
                    'disk' => $disk,
                    'path' => $path,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
