<?php

use App\Models\User;
use App\Support\Export\ExportColumns;
use App\Support\Export\ExportFile;

test('nilai kolom dibaca berurutan sesuai definisi', function () {
    $user = new User(['name' => 'Agus', 'email' => 'agus@contoh.test']);

    $columns = ExportColumns::for(User::class)
        ->add('Nama', fn (User $u) => $u->name)
        ->add('Email', fn (User $u) => $u->email);

    expect($columns->headers())->toBe(['Nama', 'Email'])
        ->and($columns->values($user))->toBe(['Agus', 'agus@contoh.test']);
});

test('model query yang tidak cocok dengan kolom ekspor gagal dengan pesan jelas', function () {
    $columns = ExportColumns::for(ExportFile::class)->add('Daftar', fn (ExportFile $f) => $f->listing);

    expect(fn () => $columns->values(new User))->toThrow(LogicException::class, 'harus model yang sama');
});
