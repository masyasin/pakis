<?php

namespace Tests\Fixtures\Approval;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen uji mesin approval (T-067): belum ada dokumen nyata Paket A. Tabelnya TEMPORARY agar
 * pembuatannya tidak meng-commit transaksi RefreshDatabase (DDL biasa di MariaDB = commit implisit).
 * Didaftarkan ke `config('pakis.approvables')` oleh TestDocument::register().
 *
 * @property int $id
 * @property string $number
 * @property string|null $amount
 * @property string $status
 * @property int|null $created_by
 * @property int|null $scope_location_id
 * @property int|null $scope_employee_id
 * @property int|null $sales_area_id
 */
class TestDocument extends Model implements Approvable
{
    public const TYPE = 'uji_dokumen';

    protected $table = 'approval_test_documents';

    protected $guarded = [];

    public $timestamps = false;

    public static function register(): void
    {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS approval_test_documents');
        Schema::create('approval_test_documents', function ($table) {
            $table->temporary();
            $table->id();
            $table->string('number', 30);
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('status', 20)->default('submitted');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('scope_location_id')->nullable();
            $table->unsignedBigInteger('scope_employee_id')->nullable();
            $table->unsignedBigInteger('sales_area_id')->nullable();
        });

        config(['pakis.approvables.'.self::TYPE => [
            'label' => 'Dokumen uji',
            'model' => self::class,
            'conditions' => ['sales_area_id'],
        ]]);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function approvalDocumentType(): string
    {
        return self::TYPE;
    }

    public function approvalAmount(): ?string
    {
        return $this->amount;
    }

    public function approvalSummary(): string
    {
        return $this->number.' · Uji';
    }

    public function approvalContext(): array
    {
        return ['catatan' => 'dokumen uji'];
    }

    public function approvalConditions(): array
    {
        return ['sales_area_id' => $this->sales_area_id];
    }

    public function approvalScopeLocationId(): ?int
    {
        return $this->scope_location_id;
    }

    public function approvalScopeEmployeeId(): ?int
    {
        return $this->scope_employee_id;
    }

    public function creatorUserId(): ?int
    {
        return $this->created_by;
    }

    public function approvalUrl(): ?string
    {
        return '/uji/'.$this->id;
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        $this->forceFill(['status' => 'approved'])->save();
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => 'rejected'])->save();
    }

    public function onCancelled(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => 'draft'])->save();
    }
}
