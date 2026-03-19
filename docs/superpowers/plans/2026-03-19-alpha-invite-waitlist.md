# Alpha Invite & Waitlist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate registration behind invite-only access with a waitlist for non-invited visitors and clickwrap agreement for alpha testers.

**Architecture:** Three new tables (`alpha_invites`, `waitlist_entries`, `alpha_agreements`) with corresponding models and services. The existing `/register` Volt component is modified to check for an invite token — showing the registration form when valid, or a waitlist signup form otherwise. Two Filament admin resources provide management UI. All token handling follows the existing `InvitationService` pattern (SHA-256 hashed, 64-char random).

**Tech Stack:** Laravel 12, Livewire 3 / Volt, Flux UI components, Filament 5, Pest

**Spec:** `docs/superpowers/specs/2026-03-19-alpha-invite-waitlist-design.md`

---

## File Structure

### New Files

| File | Responsibility |
|------|---------------|
| `config/alpha.php` | Agreement version config |
| `database/migrations/2026_03_19_000001_create_alpha_invites_table.php` | Alpha invites schema |
| `database/migrations/2026_03_19_000002_create_waitlist_entries_table.php` | Waitlist entries schema |
| `database/migrations/2026_03_19_000003_create_alpha_agreements_table.php` | Alpha agreements schema |
| `app/Models/AlphaInvite.php` | Alpha invite model |
| `app/Models/WaitlistEntry.php` | Waitlist entry model |
| `app/Models/AlphaAgreement.php` | Alpha agreement model |
| `database/factories/AlphaInviteFactory.php` | Factory for test data |
| `database/factories/WaitlistEntryFactory.php` | Factory for test data |
| `app/Services/AlphaInviteService.php` | Token generation, lookup, resend, revoke |
| `app/Services/WaitlistService.php` | Join, verify, cooldown logic |
| `app/Services/AlphaAgreementService.php` | Record agreement acceptance |
| `app/Mail/AlphaInviteMail.php` | Invite email mailable |
| `app/Mail/WaitlistVerificationMail.php` | Waitlist verification mailable |
| `app/Mail/WaitlistConfirmedMail.php` | Waitlist confirmed mailable |
| `resources/views/mail/alpha-invite.blade.php` | Invite email template |
| `resources/views/mail/waitlist-verification.blade.php` | Verification email template |
| `resources/views/mail/waitlist-confirmed.blade.php` | Confirmed email template |
| `resources/views/pages/alpha-agreement.blade.php` | Agreement text page |
| `resources/views/pages/waitlist-verify.blade.php` | Verification result page (Volt) |
| `app/Filament/Resources/AlphaInviteResource.php` | Filament CRUD + actions |
| `app/Filament/Resources/AlphaInviteResource/Pages/ListAlphaInvites.php` | List page |
| `app/Filament/Resources/AlphaInviteResource/Pages/CreateAlphaInvite.php` | Create page |
| `app/Filament/Resources/WaitlistEntryResource.php` | Read-only Filament resource |
| `app/Filament/Resources/WaitlistEntryResource/Pages/ListWaitlistEntries.php` | List page |
| `tests/Feature/Alpha/AlphaInviteServiceTest.php` | Service tests |
| `tests/Feature/Alpha/WaitlistServiceTest.php` | Service tests |
| `tests/Feature/Alpha/AlphaAgreementServiceTest.php` | Service tests |
| `tests/Feature/Alpha/RegistrationFlowTest.php` | Integration tests for register page |
| `tests/Feature/Alpha/WaitlistVerifyTest.php` | Verification endpoint tests |

### Modified Files

| File | Change |
|------|--------|
| `resources/views/pages/auth/register.blade.php` | Replace with token-gated registration + waitlist form |
| `routes/web.php` | Add `/waitlist/verify` and `/alpha-agreement` routes |

---

## Task 1: Config and Migrations

**Files:**
- Create: `config/alpha.php`
- Create: `database/migrations/2026_03_19_000001_create_alpha_invites_table.php`
- Create: `database/migrations/2026_03_19_000002_create_waitlist_entries_table.php`
- Create: `database/migrations/2026_03_19_000003_create_alpha_agreements_table.php`

- [ ] **Step 1: Create alpha config file**

Create `config/alpha.php`:

```php
<?php

return [
    'agreement_version' => '1.0',
];
```

- [ ] **Step 2: Create alpha_invites migration**

Create `database/migrations/2026_03_19_000001_create_alpha_invites_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alpha_invites', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254)->unique();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_sent_at');
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alpha_invites');
    }
};
```

- [ ] **Step 3: Create waitlist_entries migration**

Create `database/migrations/2026_03_19_000002_create_waitlist_entries_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254)->unique();
            $table->string('verification_token_hash', 64)->unique()->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
```

- [ ] **Step 4: Create alpha_agreements migration**

Create `database/migrations/2026_03_19_000003_create_alpha_agreements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alpha_agreements', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254);
            $table->string('agreement_version', 20);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->timestamps();

            $table->unique(['email', 'agreement_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alpha_agreements');
    }
};
```

- [ ] **Step 5: Run migrations**

Run: `php artisan migrate`
Expected: All three tables created successfully.

- [ ] **Step 6: Commit**

```bash
git add config/alpha.php database/migrations/2026_03_19_*
git commit -m "Add alpha invite, waitlist, and agreement migrations"
```

---

## Task 2: Models and Factories

**Files:**
- Create: `app/Models/AlphaInvite.php`
- Create: `app/Models/WaitlistEntry.php`
- Create: `app/Models/AlphaAgreement.php`
- Create: `database/factories/AlphaInviteFactory.php`
- Create: `database/factories/WaitlistEntryFactory.php`

- [ ] **Step 1: Create AlphaInvite model**

Create `app/Models/AlphaInvite.php`. Follow patterns from `app/Models/Invitation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlphaInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'token_hash',
        'last_sent_at',
        'registered_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'registered_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Transient property — holds the plaintext token after generation.
     * Never persisted to DB.
     */
    public ?string $plaintext_token = null;

    public function isPending(): bool
    {
        return is_null($this->registered_at) && is_null($this->revoked_at);
    }

    public function isRegistered(): bool
    {
        return ! is_null($this->registered_at);
    }

    public function isRevoked(): bool
    {
        return ! is_null($this->revoked_at);
    }

    public function status(): string
    {
        if ($this->isRegistered()) {
            return 'registered';
        }
        if ($this->isRevoked()) {
            return 'revoked';
        }

        return 'pending';
    }

    public function scopePending($query)
    {
        return $query->whereNull('registered_at')->whereNull('revoked_at');
    }

    public function scopeRegistered($query)
    {
        return $query->whereNotNull('registered_at');
    }

    public function findAgreement(): ?AlphaAgreement
    {
        return AlphaAgreement::where('email', $this->email)
            ->where('agreement_version', config('alpha.agreement_version'))
            ->first();
    }

    public function hasSignedAgreement(): bool
    {
        return $this->findAgreement() !== null;
    }
}
```

- [ ] **Step 2: Create WaitlistEntry model**

Create `app/Models/WaitlistEntry.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaitlistEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'verification_token_hash',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public ?string $plaintext_token = null;

    public function isVerified(): bool
    {
        return ! is_null($this->verified_at);
    }

    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_at');
    }

    public function scopeUnverified($query)
    {
        return $query->whereNull('verified_at');
    }
}
```

- [ ] **Step 3: Create AlphaAgreement model**

Create `app/Models/AlphaAgreement.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlphaAgreement extends Model
{
    protected $fillable = [
        'email',
        'agreement_version',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 4: Create AlphaInviteFactory**

Create `database/factories/AlphaInviteFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AlphaInvite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AlphaInviteFactory extends Factory
{
    protected $model = AlphaInvite::class;

    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', $token),
            'last_sent_at' => now(),
        ];
    }

    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'registered_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    /**
     * Create with a known plaintext token for testing.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'token_hash' => hash('sha256', $token),
        ]);
    }
}
```

- [ ] **Step 5: Create WaitlistEntryFactory**

Create `database/factories/WaitlistEntryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'email' => fake()->unique()->safeEmail(),
            'verification_token_hash' => hash('sha256', $token),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_token_hash' => null,
            'verified_at' => now(),
        ]);
    }

    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_token_hash' => hash('sha256', $token),
        ]);
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Models/AlphaInvite.php app/Models/WaitlistEntry.php app/Models/AlphaAgreement.php database/factories/AlphaInviteFactory.php database/factories/WaitlistEntryFactory.php
git commit -m "Add alpha invite, waitlist entry, and agreement models with factories"
```

---

## Task 3: Alpha Invite Service

**Files:**
- Create: `app/Services/AlphaInviteService.php`
- Create: `tests/Feature/Alpha/AlphaInviteServiceTest.php`

- [ ] **Step 1: Write failing tests for AlphaInviteService**

Create `tests/Feature/Alpha/AlphaInviteServiceTest.php`:

```php
<?php

use App\Models\AlphaInvite;
use App\Mail\AlphaInviteMail;
use App\Services\AlphaInviteService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->service = app(AlphaInviteService::class);
    Mail::fake();
});

it('creates an alpha invite with hashed token and sends email', function () {
    $invite = $this->service->create('alpha@example.com');

    expect($invite)->toBeInstanceOf(AlphaInvite::class);
    expect($invite->email)->toBe('alpha@example.com');
    expect($invite->token_hash)->toHaveLength(64);
    expect($invite->plaintext_token)->toHaveLength(64);
    expect($invite->plaintext_token)->not->toBe($invite->token_hash);
    expect(hash('sha256', $invite->plaintext_token))->toBe($invite->token_hash);
    expect($invite->last_sent_at)->not->toBeNull();
    expect($invite->registered_at)->toBeNull();
    expect($invite->revoked_at)->toBeNull();

    Mail::assertSent(AlphaInviteMail::class, function ($mail) {
        return $mail->hasTo('alpha@example.com');
    });
});

it('prevents duplicate invites for the same email', function () {
    $this->service->create('alpha@example.com');
    $this->service->create('alpha@example.com');
})->throws(\InvalidArgumentException::class, 'An invite already exists for this email');

it('finds a pending invite by plaintext token', function () {
    $invite = $this->service->create('alpha@example.com');
    $found = $this->service->findByToken($invite->plaintext_token);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($invite->id);
});

it('returns null for invalid token', function () {
    $found = $this->service->findByToken('invalid-token');

    expect($found)->toBeNull();
});

it('returns null for registered invite token', function () {
    $invite = $this->service->create('alpha@example.com');
    $invite->update(['registered_at' => now()]);

    $found = $this->service->findByToken($invite->plaintext_token);

    expect($found)->toBeNull();
});

it('returns null for revoked invite token', function () {
    $invite = $this->service->create('alpha@example.com');
    $invite->update(['revoked_at' => now()]);

    $found = $this->service->findByToken($invite->plaintext_token);

    expect($found)->toBeNull();
});

it('resends an invite with a new token', function () {
    $invite = $this->service->create('alpha@example.com');
    $oldHash = $invite->token_hash;

    $updated = $this->service->resend($invite);

    expect($updated->token_hash)->not->toBe($oldHash);
    expect($updated->plaintext_token)->not->toBeNull();
    expect(hash('sha256', $updated->plaintext_token))->toBe($updated->token_hash);

    Mail::assertSent(AlphaInviteMail::class, 2);
});

it('revokes a pending invite', function () {
    $invite = $this->service->create('alpha@example.com');
    $this->service->revoke($invite);

    expect($invite->fresh()->revoked_at)->not->toBeNull();
});

it('marks an invite as registered', function () {
    $invite = $this->service->create('alpha@example.com');
    $this->service->markRegistered($invite);

    expect($invite->fresh()->registered_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Alpha/AlphaInviteServiceTest.php`
Expected: FAIL — `AlphaInviteService` class not found.

- [ ] **Step 3: Create AlphaInviteMail stub**

We need a minimal mailable for the service to reference. Create `app/Mail/AlphaInviteMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\AlphaInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlphaInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AlphaInvite $invite,
        public string $registrationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invite->email],
            subject: "You've been invited to try Revat",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.alpha-invite',
        );
    }
}
```

Also create `resources/views/mail/alpha-invite.blade.php`:

```blade
<p>You've been invited to try Revat!</p>

<p>Click the link below to create your account:</p>

<p><a href="{{ $registrationUrl }}">Create your account</a></p>
```

- [ ] **Step 4: Implement AlphaInviteService**

Create `app/Services/AlphaInviteService.php`. Follow patterns from `app/Services/InvitationService.php`:

```php
<?php

namespace App\Services;

use App\Mail\AlphaInviteMail;
use App\Models\AlphaInvite;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AlphaInviteService
{
    public function create(string $email): AlphaInvite
    {
        $existing = AlphaInvite::where('email', $email)->first();
        if ($existing) {
            throw new \InvalidArgumentException('An invite already exists for this email');
        }

        $plaintextToken = Str::random(64);

        $invite = AlphaInvite::create([
            'email' => $email,
            'token_hash' => hash('sha256', $plaintextToken),
            'last_sent_at' => now(),
        ]);

        $invite->plaintext_token = $plaintextToken;

        $this->sendEmail($invite);

        return $invite;
    }

    public function findByToken(string $plaintextToken): ?AlphaInvite
    {
        return AlphaInvite::pending()
            ->where('token_hash', hash('sha256', $plaintextToken))
            ->first();
    }

    public function resend(AlphaInvite $invite): AlphaInvite
    {
        $plaintextToken = Str::random(64);

        $invite->update([
            'token_hash' => hash('sha256', $plaintextToken),
            'last_sent_at' => now(),
        ]);

        $invite->plaintext_token = $plaintextToken;

        $this->sendEmail($invite);

        return $invite;
    }

    public function revoke(AlphaInvite $invite): void
    {
        $invite->update(['revoked_at' => now()]);
    }

    public function markRegistered(AlphaInvite $invite): void
    {
        $invite->update(['registered_at' => now()]);
    }

    private function sendEmail(AlphaInvite $invite): void
    {
        $url = url('/register?token=' . $invite->plaintext_token);

        Mail::send(new AlphaInviteMail($invite, $url));
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Alpha/AlphaInviteServiceTest.php`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AlphaInviteService.php app/Mail/AlphaInviteMail.php resources/views/mail/alpha-invite.blade.php tests/Feature/Alpha/AlphaInviteServiceTest.php
git commit -m "Add AlphaInviteService with token generation, lookup, resend, and revoke"
```

---

## Task 4: Waitlist Service

**Files:**
- Create: `app/Services/WaitlistService.php`
- Create: `tests/Feature/Alpha/WaitlistServiceTest.php`

- [ ] **Step 1: Write failing tests for WaitlistService**

Create `tests/Feature/Alpha/WaitlistServiceTest.php`:

```php
<?php

use App\Models\WaitlistEntry;
use App\Mail\WaitlistVerificationMail;
use App\Mail\WaitlistConfirmedMail;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
    Mail::fake();
});

it('creates a waitlist entry and sends verification email for new email', function () {
    $entry = $this->service->join('visitor@example.com');

    expect($entry)->toBeInstanceOf(WaitlistEntry::class);
    expect($entry->email)->toBe('visitor@example.com');
    expect($entry->verification_token_hash)->toHaveLength(64);
    expect($entry->verified_at)->toBeNull();

    Mail::assertSent(WaitlistVerificationMail::class, function ($mail) {
        return $mail->hasTo('visitor@example.com');
    });
});

it('resends verification for unverified email outside cooldown', function () {
    $entry = $this->service->join('visitor@example.com');

    // Simulate 6 minutes passing
    $entry->update(['updated_at' => now()->subMinutes(6)]);

    $this->service->join('visitor@example.com');

    Mail::assertSent(WaitlistVerificationMail::class, 2);
});

it('does not resend verification for unverified email within cooldown', function () {
    $this->service->join('visitor@example.com');
    $this->service->join('visitor@example.com');

    // Only one email sent (the first join)
    Mail::assertSent(WaitlistVerificationMail::class, 1);
});

it('returns null and sends no email for already verified email', function () {
    WaitlistEntry::factory()->verified()->create(['email' => 'visitor@example.com']);

    $result = $this->service->join('visitor@example.com');

    expect($result)->toBeNull();
    Mail::assertNothingSent();
});

it('verifies a waitlist entry by token and sends confirmation', function () {
    $entry = $this->service->join('visitor@example.com');
    $token = $entry->plaintext_token;

    $verified = $this->service->verify($token);

    expect($verified)->toBeTrue();

    $entry->refresh();
    expect($entry->verified_at)->not->toBeNull();
    expect($entry->verification_token_hash)->toBeNull();

    Mail::assertSent(WaitlistConfirmedMail::class, function ($mail) {
        return $mail->hasTo('visitor@example.com');
    });
});

it('returns false for invalid verification token', function () {
    $result = $this->service->verify('invalid-token');

    expect($result)->toBeFalse();
});

it('returns false for already verified token', function () {
    $entry = $this->service->join('visitor@example.com');
    $token = $entry->plaintext_token;

    $this->service->verify($token);
    $result = $this->service->verify($token);

    expect($result)->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Alpha/WaitlistServiceTest.php`
Expected: FAIL — `WaitlistService` class not found.

- [ ] **Step 3: Create WaitlistVerificationMail and WaitlistConfirmedMail stubs**

Create `app/Mail/WaitlistVerificationMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WaitlistVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->email],
            subject: 'Confirm your spot on the Revat waitlist',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.waitlist-verification',
        );
    }
}
```

Create `resources/views/mail/waitlist-verification.blade.php`:

```blade
<p>Thanks for your interest in Revat!</p>

<p>Please confirm your email to join the waitlist:</p>

<p><a href="{{ $verificationUrl }}">Confirm my email</a></p>
```

Create `app/Mail/WaitlistConfirmedMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WaitlistConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->email],
            subject: "You're on the Revat waitlist",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.waitlist-confirmed',
        );
    }
}
```

Create `resources/views/mail/waitlist-confirmed.blade.php`:

```blade
<p>You're on the Revat waitlist!</p>

<p>We'll let you know when it's your turn to get access. Thanks for your patience.</p>
```

- [ ] **Step 4: Implement WaitlistService**

Create `app/Services/WaitlistService.php`:

```php
<?php

namespace App\Services;

use App\Mail\WaitlistConfirmedMail;
use App\Mail\WaitlistVerificationMail;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WaitlistService
{
    private const COOLDOWN_MINUTES = 5;

    public function join(string $email): ?WaitlistEntry
    {
        $existing = WaitlistEntry::where('email', $email)->first();

        if ($existing && $existing->isVerified()) {
            return null;
        }

        if ($existing && $existing->updated_at->addMinutes(self::COOLDOWN_MINUTES)->isFuture()) {
            return $existing;
        }

        $plaintextToken = Str::random(64);
        $tokenHash = hash('sha256', $plaintextToken);

        if ($existing) {
            $existing->update(['verification_token_hash' => $tokenHash]);
            $existing->plaintext_token = $plaintextToken;
            $this->sendVerificationEmail($existing);

            return $existing;
        }

        $entry = WaitlistEntry::create([
            'email' => $email,
            'verification_token_hash' => $tokenHash,
        ]);

        $entry->plaintext_token = $plaintextToken;
        $this->sendVerificationEmail($entry);

        return $entry;
    }

    public function verify(string $plaintextToken): bool
    {
        $entry = WaitlistEntry::unverified()
            ->where('verification_token_hash', hash('sha256', $plaintextToken))
            ->first();

        if (! $entry) {
            return false;
        }

        $entry->update([
            'verified_at' => now(),
            'verification_token_hash' => null,
        ]);

        Mail::send(new WaitlistConfirmedMail($entry->email));

        return true;
    }

    private function sendVerificationEmail(WaitlistEntry $entry): void
    {
        $url = url('/waitlist/verify?token=' . $entry->plaintext_token);

        Mail::send(new WaitlistVerificationMail($entry->email, $url));
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Alpha/WaitlistServiceTest.php`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WaitlistService.php app/Mail/WaitlistVerificationMail.php app/Mail/WaitlistConfirmedMail.php resources/views/mail/waitlist-verification.blade.php resources/views/mail/waitlist-confirmed.blade.php tests/Feature/Alpha/WaitlistServiceTest.php
git commit -m "Add WaitlistService with join, verify, cooldown, and double opt-in"
```

---

## Task 5: Alpha Agreement Service

**Files:**
- Create: `app/Services/AlphaAgreementService.php`
- Create: `tests/Feature/Alpha/AlphaAgreementServiceTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Alpha/AlphaAgreementServiceTest.php`:

```php
<?php

use App\Models\AlphaAgreement;
use App\Services\AlphaAgreementService;

beforeEach(function () {
    $this->service = app(AlphaAgreementService::class);
});

it('records an agreement acceptance', function () {
    $this->service->record(
        email: 'alpha@example.com',
        ipAddress: '192.168.1.1',
        userAgent: 'Mozilla/5.0 Test'
    );

    $agreement = AlphaAgreement::where('email', 'alpha@example.com')->first();

    expect($agreement)->not->toBeNull();
    expect($agreement->agreement_version)->toBe('1.0');
    expect($agreement->ip_address)->toBe('192.168.1.1');
    expect($agreement->user_agent)->toBe('Mozilla/5.0 Test');
    expect($agreement->accepted_at)->not->toBeNull();
});

it('prevents duplicate agreement for same email and version', function () {
    $this->service->record('alpha@example.com', '192.168.1.1', 'UA1');
    $this->service->record('alpha@example.com', '192.168.1.2', 'UA2');
})->throws(\InvalidArgumentException::class);

it('uses the configured agreement version', function () {
    config(['alpha.agreement_version' => '2.0']);

    $this->service->record('alpha@example.com', '1.2.3.4', 'UA');

    $agreement = AlphaAgreement::where('email', 'alpha@example.com')->first();
    expect($agreement->agreement_version)->toBe('2.0');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Alpha/AlphaAgreementServiceTest.php`
Expected: FAIL — `AlphaAgreementService` class not found.

- [ ] **Step 3: Implement AlphaAgreementService**

Create `app/Services/AlphaAgreementService.php`:

```php
<?php

namespace App\Services;

use App\Models\AlphaAgreement;

class AlphaAgreementService
{
    public function record(string $email, string $ipAddress, string $userAgent): AlphaAgreement
    {
        $version = config('alpha.agreement_version');

        $existing = AlphaAgreement::where('email', $email)
            ->where('agreement_version', $version)
            ->first();

        if ($existing) {
            throw new \InvalidArgumentException('Agreement already recorded for this email and version');
        }

        return AlphaAgreement::create([
            'email' => $email,
            'agreement_version' => $version,
            'accepted_at' => now(),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Alpha/AlphaAgreementServiceTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AlphaAgreementService.php tests/Feature/Alpha/AlphaAgreementServiceTest.php
git commit -m "Add AlphaAgreementService for clickwrap audit trail"
```

---

## Task 6: Routes and Static Pages

**Files:**
- Create: `resources/views/pages/alpha-agreement.blade.php`
- Create: `resources/views/pages/waitlist-verify.blade.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create alpha agreement page**

Create `resources/views/pages/alpha-agreement.blade.php`. This is a simple static page. Use the `<x-layouts.auth-split>` layout for consistency with auth pages:

```blade
<x-layouts.auth-split>
    <div class="prose prose-sm max-w-none dark:prose-invert">
        <h1>Alpha Testing Agreement</h1>
        <p><strong>Version 1.0</strong> — Effective as of March 2026</p>

        <p>TODO: Replace this placeholder with the actual alpha testing agreement text.</p>

        <h2>1. Alpha Program</h2>
        <p>By participating in the Revat alpha testing program ("Program"), you agree to the following terms.</p>

        <h2>2. Confidentiality</h2>
        <p>You agree to keep all aspects of the Program confidential, including but not limited to features, functionality, and performance characteristics.</p>

        <h2>3. Feedback</h2>
        <p>Any feedback, suggestions, or ideas you provide may be used by Revat without obligation or compensation.</p>

        <h2>4. No Warranty</h2>
        <p>The software is provided "as is" without warranty of any kind. The service may be interrupted, limited, or discontinued at any time.</p>

        <h2>5. Data Processing</h2>
        <p>Your data will be processed in accordance with our Privacy Policy. By accepting this agreement, you consent to the processing of your personal data as described therein.</p>

        <h2>6. Governing Law</h2>
        <p>This agreement is governed by the laws of France.</p>
    </div>
</x-layouts.auth-split>
```

- [ ] **Step 2: Create waitlist verification page**

Create `resources/views/pages/waitlist-verify.blade.php` as a Volt component that handles the token verification:

```blade
<?php

use App\Services\WaitlistService;
use Livewire\Volt\Component;

new class extends Component {
    public bool $verified = false;
    public bool $invalid = false;

    public function mount(): void
    {
        $token = request()->query('token');

        if (! $token) {
            $this->invalid = true;
            return;
        }

        $service = app(WaitlistService::class);
        $result = $service->verify($token);

        if ($result) {
            $this->verified = true;
        } else {
            $this->invalid = true;
        }
    }
}; ?>

<x-layouts.auth-split>
    @volt('waitlist.verify')
        <div class="space-y-6">
            @if ($verified)
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.heading>You're confirmed!</flux:callout.heading>
                    <flux:callout.text>We'll let you know when it's your turn to get access. Thanks for your patience.</flux:callout.text>
                </flux:callout>
            @else
                <flux:callout variant="danger" icon="x-circle">
                    <flux:callout.heading>Invalid link</flux:callout.heading>
                    <flux:callout.text>This verification link is no longer valid.</flux:callout.text>
                </flux:callout>
            @endif

            <div>
                <a href="/" class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    &larr; Back to home
                </a>
            </div>
        </div>
    @endvolt
</x-layouts.auth-split>
```

- [ ] **Step 3: Add routes to web.php**

In `routes/web.php`, add the new routes. `/waitlist/verify` goes inside the existing `guest` middleware group (around line 111). `/alpha-agreement` goes outside the guest group since it should be accessible to anyone (place it near the invitation route):

```php
// Inside the Route::middleware('guest')->group(function () { ... });
// Add after the existing /register route:

Route::get('/waitlist/verify', fn () => view('pages.waitlist-verify'))
    ->name('waitlist.verify')
    ->middleware('throttle:10,1');
```

```php
// Outside the guest group, near the invitation route:

Route::get('/alpha-agreement', fn () => view('pages.alpha-agreement'))
    ->name('alpha.agreement');
```

- [ ] **Step 4: Verify routes are registered**

Run: `php artisan route:list --name=waitlist && php artisan route:list --name=alpha`
Expected: Both routes listed with correct methods and middleware.

- [ ] **Step 5: Commit**

```bash
git add resources/views/pages/alpha-agreement.blade.php resources/views/pages/waitlist-verify.blade.php routes/web.php
git commit -m "Add alpha agreement page, waitlist verify endpoint, and routes"
```

---

## Task 7: Modify Registration Page

This is the most complex task — the existing `/register` Volt component needs to handle three states: waitlist form (no token), registration form (valid token), and error state (invalid token).

**Files:**
- Modify: `resources/views/pages/auth/register.blade.php`
- Create: `tests/Feature/Alpha/RegistrationFlowTest.php`

- [ ] **Step 1: Write failing integration tests**

Create `tests/Feature/Alpha/RegistrationFlowTest.php`. Reference existing test patterns from `tests/Feature/Billing/CheckoutTest.php`:

```php
<?php

use App\Models\AlphaAgreement;
use App\Models\AlphaInvite;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Mail\WaitlistVerificationMail;
use App\Services\AlphaInviteService;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Mail::fake();
});

// --- Registration with valid token ---

it('shows registration form when visiting /register with valid token', function () {
    $invite = app(AlphaInviteService::class)->create('alpha@example.com');

    $response = $this->get('/register?token=' . $invite->plaintext_token);

    $response->assertStatus(200);
    $response->assertSee('alpha@example.com');
    $response->assertSee('Alpha Testing Agreement');
});

it('registers a new user with valid token and agreement', function () {
    $invite = app(AlphaInviteService::class)->create('alpha@example.com');

    Volt::test('auth.register', ['token' => $invite->plaintext_token])
        ->set('name', 'Alpha Tester')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->set('agree_to_terms', true)
        ->call('register')
        ->assertRedirect(route('dashboard'));

    // User created
    expect(User::where('email', 'alpha@example.com')->exists())->toBeTrue();

    // Invite marked registered
    expect($invite->fresh()->registered_at)->not->toBeNull();

    // Agreement recorded
    $agreement = AlphaAgreement::where('email', 'alpha@example.com')->first();
    expect($agreement)->not->toBeNull();
    expect($agreement->agreement_version)->toBe('1.0');
});

it('rejects registration without agreement checkbox', function () {
    $invite = app(AlphaInviteService::class)->create('alpha@example.com');

    Volt::test('auth.register', ['token' => $invite->plaintext_token])
        ->set('name', 'Alpha Tester')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        // agree_to_terms intentionally omitted
        ->call('register')
        ->assertHasErrors(['agree_to_terms']);

    expect(User::where('email', 'alpha@example.com')->exists())->toBeFalse();
});

it('redirects to login if email already has an account', function () {
    $invite = app(AlphaInviteService::class)->create('existing@example.com');
    User::factory()->create(['email' => 'existing@example.com']);

    Volt::test('auth.register', ['token' => $invite->plaintext_token])
        ->set('name', 'Existing User')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->set('agree_to_terms', true)
        ->call('register')
        ->assertRedirect(route('login'));

    expect($invite->fresh()->registered_at)->not->toBeNull();
});

// --- Waitlist (no token) ---

it('shows waitlist form when visiting /register without token', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
    $response->assertSee('Join the Waitlist');
});

it('shows waitlist form with error for invalid token', function () {
    $response = $this->get('/register?token=invalid-token');

    $response->assertStatus(200);
    $response->assertSee('This invite link is no longer valid');
    $response->assertSee('Join the Waitlist');
});

it('submits waitlist form and shows success', function () {
    Volt::test('auth.register')
        ->set('waitlist_email', 'visitor@example.com')
        ->call('joinWaitlist');

    expect(WaitlistEntry::where('email', 'visitor@example.com')->exists())->toBeTrue();
    Mail::assertSent(WaitlistVerificationMail::class);
});

// --- Waitlist verify endpoint ---

it('verifies a waitlist entry via GET /waitlist/verify', function () {
    $service = app(\App\Services\WaitlistService::class);
    $entry = $service->join('visitor@example.com');

    $response = $this->get('/waitlist/verify?token=' . $entry->plaintext_token);

    $response->assertStatus(200);
    $response->assertSee("You're confirmed");
    expect($entry->fresh()->verified_at)->not->toBeNull();
});

it('shows error for invalid waitlist verify token', function () {
    $response = $this->get('/waitlist/verify?token=bad-token');

    $response->assertStatus(200);
    $response->assertSee('no longer valid');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Alpha/RegistrationFlowTest.php`
Expected: FAIL — registration page doesn't have waitlist form or token handling yet.

- [ ] **Step 3: Rewrite the register Volt component**

Read the current `resources/views/pages/auth/register.blade.php` before modifying. The new version replaces the component logic to handle three states.

Modify `resources/views/pages/auth/register.blade.php`:

The Volt component's PHP section becomes:

```php
<?php

use App\Models\User;
use App\Models\AlphaInvite;
use App\Models\WaitlistEntry;
use App\Services\AlphaInviteService;
use App\Services\AlphaAgreementService;
use App\Services\WaitlistService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    // Registration fields
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public bool $agree_to_terms = false;
    public string $token = '';

    // Waitlist fields
    public string $waitlist_email = '';

    // State
    public ?AlphaInvite $invite = null;
    public bool $showRegistration = false;
    public bool $showWaitlist = false;
    public bool $tokenError = false;
    public bool $registered = false;
    public bool $waitlistSubmitted = false;

    public function mount(): void
    {
        $this->token = request()->query('token', '');

        if ($this->token) {
            $service = app(AlphaInviteService::class);
            $this->invite = $service->findByToken($this->token);

            if ($this->invite) {
                $this->showRegistration = true;
                $this->email = $this->invite->email;
            } else {
                $this->tokenError = true;
                $this->showWaitlist = true;
            }
        } else {
            $this->showWaitlist = true;
        }
    }

    public function register(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
            'agree_to_terms' => 'accepted',
        ]);

        // Re-validate token
        $service = app(AlphaInviteService::class);
        $invite = $service->findByToken($this->token);

        if (! $invite) {
            $this->tokenError = true;
            $this->showRegistration = false;
            $this->showWaitlist = true;
            return;
        }

        // Record agreement BEFORE user creation (audit trail)
        app(AlphaAgreementService::class)->record(
            email: $invite->email,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        // Check if user already exists
        if (User::where('email', $invite->email)->exists()) {
            $service->markRegistered($invite);
            $this->redirect(route('login'), navigate: false);
            session()->flash('status', 'You already have an account. Please log in.');
            return;
        }

        // Create user
        $user = User::create([
            'name' => $this->name,
            'email' => $invite->email,
            'password' => $this->password,
        ]);

        $service->markRegistered($invite);

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }

    public function joinWaitlist(): void
    {
        $this->validate([
            'waitlist_email' => 'required|email|max:254',
        ]);

        app(WaitlistService::class)->join($this->waitlist_email);

        $this->waitlistSubmitted = true;
    }
}; ?>
```

The Blade template section becomes:

```blade
<x-layouts.auth-split>
    <x-slot:title>Register</x-slot:title>

    @volt('auth.register')
        <div class="space-y-6">
            @if ($tokenError)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>Invalid invite link</flux:callout.heading>
                    <flux:callout.text>This invite link is no longer valid. You can join the waitlist below.</flux:callout.text>
                </flux:callout>
            @endif

            @if ($showRegistration)
                {{-- Alpha invite registration form --}}
                @if ($registered)
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.heading>Account created!</flux:callout.heading>
                        <flux:callout.text>Redirecting you to the dashboard...</flux:callout.text>
                    </flux:callout>
                @else
                    <div>
                        <h1 class="text-xl font-semibold">Create your account</h1>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">You've been invited to join the Revat alpha.</p>
                    </div>

                    <form wire:submit="register" class="space-y-4">
                        <flux:input wire:model="name" label="Name" type="text" required autofocus />
                        <flux:input wire:model="email" label="Email" type="email" readonly />
                        <flux:input wire:model="password" label="Password" type="password" required />
                        <flux:input wire:model="password_confirmation" label="Confirm password" type="password" required />

                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="agree_to_terms" class="mt-1 rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-600 dark:bg-zinc-800" />
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">
                                I agree to the <a href="{{ route('alpha.agreement') }}" target="_blank" class="underline hover:text-zinc-900 dark:hover:text-zinc-200">Alpha Testing Agreement</a>
                            </span>
                        </label>
                        @error('agree_to_terms')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror

                        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                            Create account
                        </flux:button>
                    </form>

                    <p class="text-sm text-center text-zinc-500 dark:text-zinc-400">
                        Already have an account? <a href="{{ route('login') }}" class="underline hover:text-zinc-900 dark:hover:text-zinc-200" wire:navigate>Log in</a>
                    </p>
                @endif
            @endif

            @if ($showWaitlist)
                {{-- Waitlist signup form --}}
                @if ($waitlistSubmitted)
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.heading>Check your email</flux:callout.heading>
                        <flux:callout.text>We've sent a confirmation link to verify your spot on the waitlist.</flux:callout.text>
                    </flux:callout>
                @else
                    <div>
                        <h1 class="text-xl font-semibold">Join the waitlist</h1>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Revat is currently in private alpha. Enter your email to be notified when we open up access.</p>
                    </div>

                    <form wire:submit="joinWaitlist" class="space-y-4">
                        <flux:input wire:model="waitlist_email" label="Email" type="email" required autofocus placeholder="you@company.com" />

                        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                            Join the Waitlist
                        </flux:button>
                    </form>

                    <p class="text-sm text-center text-zinc-500 dark:text-zinc-400">
                        Already have an account? <a href="{{ route('login') }}" class="underline hover:text-zinc-900 dark:hover:text-zinc-200" wire:navigate>Log in</a>
                    </p>
                @endif
            @endif
        </div>
    @endvolt
</x-layouts.auth-split>
```

- [ ] **Step 4: Run integration tests**

Run: `php artisan test tests/Feature/Alpha/RegistrationFlowTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Manual smoke test**

1. Visit `/register` — should see waitlist form
2. Visit `/register?token=invalid` — should see error + waitlist form
3. Create an invite via tinker: `app(AlphaInviteService::class)->create('test@example.com')`
4. Visit `/register?token={token}` — should see registration form with locked email
5. Visit `/alpha-agreement` — should see agreement text
6. Visit `/waitlist/verify?token=bad` — should see error

- [ ] **Step 6: Commit**

```bash
git add resources/views/pages/auth/register.blade.php tests/Feature/Alpha/RegistrationFlowTest.php
git commit -m "Modify registration page for alpha invite gating and waitlist"
```

---

## Task 8: Filament AlphaInviteResource

**Files:**
- Create: `app/Filament/Resources/AlphaInviteResource.php`
- Create: `app/Filament/Resources/AlphaInviteResource/Pages/ListAlphaInvites.php`
- Create: `app/Filament/Resources/AlphaInviteResource/Pages/CreateAlphaInvite.php`

- [ ] **Step 1: Create AlphaInviteResource**

Create `app/Filament/Resources/AlphaInviteResource.php`. Follow patterns from `app/Filament/Resources/UserResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AlphaInviteResource\Pages;
use App\Models\AlphaInvite;
use App\Services\AlphaInviteService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AlphaInviteResource extends Resource
{
    protected static ?string $model = AlphaInvite::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Alpha Invites';

    protected static string|\UnitEnum|null $navigationGroup = 'Alpha';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(254)
                ->unique(AlphaInvite::class, 'email'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_sent_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->getStateUsing(fn (AlphaInvite $record) => $record->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'registered' => 'success',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('agreement_signed')
                    ->getStateUsing(fn (AlphaInvite $record) => $record->hasSignedAgreement())
                    ->boolean()
                    ->label('Agreement'),
                Tables\Columns\TextColumn::make('registered_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'registered' => 'Registered',
                        'revoked' => 'Revoked',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value']) {
                            'pending' => $query->whereNull('registered_at')->whereNull('revoked_at'),
                            'registered' => $query->whereNotNull('registered_at'),
                            'revoked' => $query->whereNotNull('revoked_at'),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('resend')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->visible(fn (AlphaInvite $record) => $record->isPending())
                    ->action(function (AlphaInvite $record) {
                        app(AlphaInviteService::class)->resend($record);
                        Notification::make()->title('Invite resent')->success()->send();
                    }),
                Tables\Actions\Action::make('revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (AlphaInvite $record) => $record->isPending())
                    ->action(function (AlphaInvite $record) {
                        app(AlphaInviteService::class)->revoke($record);
                        Notification::make()->title('Invite revoked')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlphaInvites::route('/'),
            'create' => Pages\CreateAlphaInvite::route('/create'),
        ];
    }
}
```

- [ ] **Step 2: Create List page**

Create `app/Filament/Resources/AlphaInviteResource/Pages/ListAlphaInvites.php`:

```php
<?php

namespace App\Filament\Resources\AlphaInviteResource\Pages;

use App\Filament\Resources\AlphaInviteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAlphaInvites extends ListRecords
{
    protected static string $resource = AlphaInviteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
```

- [ ] **Step 3: Create custom Create page**

The create page needs to use `AlphaInviteService` instead of a direct model create, so the token is generated and email is sent.

Create `app/Filament/Resources/AlphaInviteResource/Pages/CreateAlphaInvite.php`:

```php
<?php

namespace App\Filament\Resources\AlphaInviteResource\Pages;

use App\Filament\Resources\AlphaInviteResource;
use App\Services\AlphaInviteService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateAlphaInvite extends CreateRecord
{
    protected static string $resource = AlphaInviteResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return app(AlphaInviteService::class)->create($data['email']);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Alpha invite sent';
    }
}
```

- [ ] **Step 4: Verify in browser**

1. Log in to `/admin`
2. Navigate to "Alpha" → "Alpha Invites" in sidebar
3. Click "New Alpha Invite" — enter an email — submit
4. Verify invite appears in the table with "pending" badge
5. Test "Resend" and "Revoke" actions

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/AlphaInviteResource.php app/Filament/Resources/AlphaInviteResource/
git commit -m "Add Filament AlphaInviteResource with resend and revoke actions"
```

---

## Task 9: Filament WaitlistEntryResource

**Files:**
- Create: `app/Filament/Resources/WaitlistEntryResource.php`
- Create: `app/Filament/Resources/WaitlistEntryResource/Pages/ListWaitlistEntries.php`

- [ ] **Step 1: Create WaitlistEntryResource**

Create `app/Filament/Resources/WaitlistEntryResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WaitlistEntryResource\Pages;
use App\Models\WaitlistEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WaitlistEntryResource extends Resource
{
    protected static ?string $model = WaitlistEntry::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Waitlist';

    protected static string|\UnitEnum|null $navigationGroup = 'Alpha';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->getStateUsing(fn (WaitlistEntry $record) => $record->isVerified() ? 'verified' : 'unverified')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'verified' => 'success',
                        'unverified' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('verified_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'verified' => 'Verified',
                        'unverified' => 'Unverified',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value']) {
                            'verified' => $query->whereNotNull('verified_at'),
                            'unverified' => $query->whereNull('verified_at'),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWaitlistEntries::route('/'),
        ];
    }
}
```

- [ ] **Step 2: Create List page**

Create `app/Filament/Resources/WaitlistEntryResource/Pages/ListWaitlistEntries.php`:

```php
<?php

namespace App\Filament\Resources\WaitlistEntryResource\Pages;

use App\Filament\Resources\WaitlistEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListWaitlistEntries extends ListRecords
{
    protected static string $resource = WaitlistEntryResource::class;
}
```

- [ ] **Step 3: Verify in browser**

1. Log in to `/admin`
2. Navigate to "Alpha" → "Waitlist" in sidebar
3. Verify table renders (may be empty)
4. Verify no "Create" button is shown (read-only)

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/WaitlistEntryResource.php app/Filament/Resources/WaitlistEntryResource/
git commit -m "Add read-only Filament WaitlistEntryResource"
```

---

## Task 10: Run Full Test Suite

- [ ] **Step 1: Run all alpha tests**

Run: `php artisan test tests/Feature/Alpha/`
Expected: All tests PASS.

- [ ] **Step 2: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: All existing tests still PASS. No regressions.

- [ ] **Step 3: Fix any failures**

If any tests fail, investigate and fix. Known issues:
- `tests/Feature/Auth/AuthPagesTest.php` will break — tests that render the register page will see "Join the waitlist" instead of "Create your account". Tests that call `Volt::test('auth.register')` and invoke `register()` without a token will fail because the component now shows the waitlist form.
- Fix: Update register page tests to provide a valid token via `Volt::test('auth.register', ['token' => $invite->plaintext_token])`. Add new tests for the waitlist state (no token).
- The test `it('does not reveal whether an email is already taken during registration')` should be updated to pass a token since registration is now gated.

- [ ] **Step 4: Final commit if any fixes were needed**

```bash
git add -A
git commit -m "Fix test regressions from alpha invite system"
```

---

## Task 11: End-to-End Verification

- [ ] **Step 1: Test the complete alpha invite flow**

1. Log in to `/admin`
2. Create a new alpha invite for `test-alpha@example.com`
3. Check mail log (or Mailpit/Mailtrap) for the invite email
4. Copy the registration URL from the email
5. Open in an incognito browser — should see registration form with locked email
6. Fill in name, password, check agreement box, submit
7. Verify user is created and logged in
8. Verify invite shows as "registered" in admin panel
9. Verify agreement record exists in `alpha_agreements` table

- [ ] **Step 2: Test the waitlist flow**

1. Visit `/register` in incognito — should see waitlist form
2. Enter an email, submit — should see "check your email" message
3. Check mail log for verification email
4. Click verification link — should see "You're confirmed" page
5. Check mail log for confirmation email
6. Verify entry shows as "verified" in admin panel

- [ ] **Step 3: Test edge cases**

1. Visit `/register?token=garbage` — should see error + waitlist form
2. Try registering without checking agreement box — should fail validation
3. Try the waitlist with an already-verified email — should show success (no leak)
4. Try revoking an invite in admin, then using its token — should show error

- [ ] **Step 4: Commit any final adjustments**

```bash
git add -A
git commit -m "Final adjustments from end-to-end alpha invite verification"
```
