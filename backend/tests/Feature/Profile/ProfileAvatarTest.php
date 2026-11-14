<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Avatar profil (FR-010, SEC-050, SEC-052): allowlist isi berkas, di-encode ulang (EXIF/muatan
 * terbuang), nama acak, disk PRIVAT, hanya bisa dibuka pemiliknya lewat /profil/foto.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create(['name' => 'Siti Aminah', 'email' => 'siti@contoh.test', 'phone' => null]);
    $this->actingAs($this->user);
});

function uploadAvatar(UploadedFile $file)
{
    return test()->from('/profil')->put('/profil', [
        'name' => 'Siti Aminah',
        'email' => 'siti@contoh.test',
        'avatar' => $file,
    ]);
}

test('SEC-050 avatar diterima, di-encode ulang jadi WebP persegi 256 px di disk privat', function (string $name) {
    uploadAvatar(UploadedFile::fake()->image($name, 640, 400))->assertSessionHasNoErrors();

    $path = $this->user->refresh()->avatar_path;
    expect($path)->toStartWith('avatars/')->toEndWith('.webp');
    Storage::disk('local')->assertExists($path);

    $info = getimagesizefromstring(Storage::disk('local')->get($path));
    expect($info[0])->toBe(256)->and($info[1])->toBe(256)->and($info[2])->toBe(IMAGETYPE_WEBP);
})->with(['foto.png', 'foto.jpg', 'foto.webp']);

test('SEC-052 SVG, PHP berganti nama, dan berkas > 2 MB ditolak', function (Closure $file) {
    uploadAvatar($file())->assertSessionHasErrors('avatar');

    expect($this->user->refresh()->avatar_path)->toBeNull();
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'php sebagai png' => fn () => UploadedFile::fake()->createWithContent('a.png', '<?php echo "x"; ?>'),
    'terlalu besar' => fn () => UploadedFile::fake()->image('besar.png', 300, 300)->size(2100),
]);

test('ganti avatar menghapus berkas lama; remove_avatar menghapus avatar', function () {
    uploadAvatar(UploadedFile::fake()->image('a.png', 300, 300))->assertSessionHasNoErrors();
    $first = $this->user->refresh()->avatar_path;

    uploadAvatar(UploadedFile::fake()->image('b.png', 300, 300))->assertSessionHasNoErrors();
    $second = $this->user->refresh()->avatar_path;
    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing($first);

    $this->put('/profil', ['name' => 'Siti Aminah', 'email' => 'siti@contoh.test', 'remove_avatar' => true])->assertSessionHasNoErrors();
    expect($this->user->refresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($second);
});

test('/profil/foto hanya menyajikan avatar milik sendiri; tanpa avatar → 404', function () {
    $this->get('/profil/foto')->assertNotFound();

    uploadAvatar(UploadedFile::fake()->image('a.png', 300, 300))->assertSessionHasNoErrors();

    $this->get('/profil/foto')->assertOk()->assertHeader('Content-Type', 'image/webp');

    // Pengguna lain melihat avatarnya sendiri (tidak ada parameter id yang bisa ditebak).
    $other = User::factory()->create();
    $this->actingAs($other)->get('/profil/foto')->assertNotFound();
});

test('auth.user.avatar memakai URL relatif /profil/foto (lolos CSP img-src self)', function () {
    uploadAvatar(UploadedFile::fake()->image('a.png', 300, 300))->assertSessionHasNoErrors();

    $this->get('/profil')->assertInertia(fn ($page) => $page->where('auth.user.avatar', fn ($url) => str_starts_with((string) $url, '/profil/foto?v=')));
});
