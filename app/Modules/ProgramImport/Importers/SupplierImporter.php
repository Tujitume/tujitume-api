<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\SupplierDirectory;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;

/** Supplier directory entries, de-duplicated by legal name + email/phone. No welcome email: silent mode blocks it. */
class SupplierImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        if ($id = $c->find('supplier', $row->external_ref, null)) {
            return ['supplier', $id];
        }
        $owner = $c->owner();
        $dupe = SupplierDirectory::where('added_by', $owner->id)->whereRaw('lower(legal_name) = ?', [mb_strtolower($v['legal_name'])])
            ->where(function ($q) use ($v) {
                $v['email'] && $q->orWhere('email', $v['email']);
                $v['phone'] && $q->orWhere('phone', $v['phone']);
                ! $v['email'] && ! $v['phone'] && $q->orWhereRaw('1 = 1');
            })->first();
        if ($dupe) {
            $c->record($dupe, 'linked');
            $c->link('supplier', $dupe, $row->external_ref);

            return ['supplier', $dupe->id];
        }
        $s = SupplierDirectory::create([
            'user_id' => null, 'added_by' => $owner->id, 'legal_name' => $v['legal_name'], 'email' => $v['email'], 'phone' => $v['phone'],
            'payment_method' => $v['payment_method'] ?? 'other', 'notes' => $v['payment_details'] ?? null, 'is_active' => true,
        ]);
        $c->record($s, 'created');
        $c->link('supplier', $s, $row->external_ref);

        return ['supplier', $s->id];
    }
}
