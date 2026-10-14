<?php

declare(strict_types=1);

/** Bounded recovery data, retained until success or expiry. Its identifier grants no authorization. */
final class ContractFormDraft
{
    private const LIMIT = 12;
    private const TTL = 1800;
    private const FIELDS = [
        'adicionar-produto' => ['nome'=>150,'marca'=>100,'unidade'=>30,'quantidade'=>32,'preco'=>32],
        'editar-produto' => ['nome'=>150,'marca'=>100,'unidade'=>30,'quantidade'=>32,'preco'=>32],
        'observacao' => ['observacao'=>2000],
        'nova-folha' => ['observacao'=>2000],
        'estoque' => ['minimo'=>32,'maximo'=>32,'abertura'=>32,'confirmar_abertura'=>1],
        'conferir-abertura' => ['quantidade'=>32,'unidade'=>30,'minimo'=>32,'maximo'=>32,'motivo'=>180,'confirmar'=>1],
        'movimentar' => ['tipo'=>10,'quantidade'=>32,'motivo'=>300],
        'estornar' => ['original'=>32,'motivo'=>300],
        'faturar' => ['data'=>10,'motivo'=>180,'faturado'=>1],
    ];

    public static function context(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D',$value)===1 ? $value : bin2hex(random_bytes(16));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public static function fields(string $operation,array $input): array
    {
        $fields=[];
        foreach (self::FIELDS[$operation] ?? [] as $name=>$limit) {
            $value=$input[$name] ?? ($name==='faturado' ? '' : null);
            if (is_string($value) && mb_check_encoding($value,'UTF-8') && !str_contains($value,"\0")) {
                $fields[$name]=mb_substr($value,0,$limit,'UTF-8');
            }
        }
        return $fields;
    }

    /** @param array<string,mixed> $input */
    public static function save(int $contract,string $operation,int $sheet,int $item,array $input,string $message=''): string
    {
        if (!isset(self::FIELDS[$operation]) || $contract<1 || $sheet<0 || $item<0) { throw new InvalidArgumentException('Recurso de formulário inválido.'); }
        self::prune();
        $id=bin2hex(random_bytes(16));
        $revision=filter_var($input['revisao'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]);
        $_SESSION['contract_form_recovery'][$id]=[
            'contract'=>$contract,'operation'=>$operation,'sheet'=>$sheet,'item'=>$item,
            'context'=>self::context($input['_form_context'] ?? null),
            'revision'=>$revision===false ? null : $revision,
            'fields'=>self::fields($operation,$input),'message'=>mb_substr($message,0,500,'UTF-8'),'created'=>time(),
        ];
        while (count($_SESSION['contract_form_recovery'])>self::LIMIT) { array_shift($_SESSION['contract_form_recovery']); }
        return $id;
    }

    /** @return array<string,mixed>|null */
    public static function read(int $contract,mixed $id): ?array
    {
        self::prune();
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D',$id)!==1) { return null; }
        $draft=$_SESSION['contract_form_recovery'][$id] ?? null;
        if (!is_array($draft) || ($draft['contract'] ?? null)!==$contract) { return null; }
        return $draft;
    }

    public static function clear(int $contract,string $operation,int $sheet,int $item,mixed $context): void
    {
        self::prune();
        if (!is_string($context)) { return; }
        foreach ($_SESSION['contract_form_recovery'] as $id=>$draft) {
            if ($draft['contract']===$contract && $draft['operation']===$operation && $draft['sheet']===$sheet
                && $draft['item']===$item && $draft['context']===$context) {
                unset($_SESSION['contract_form_recovery'][$id]);
            }
        }
    }

    private static function prune(): void
    {
        $drafts=$_SESSION['contract_form_recovery'] ?? [];
        $_SESSION['contract_form_recovery']=[];
        if (!is_array($drafts)) { return; }
        foreach ($drafts as $id=>$draft) {
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D',$id)!==1 || !is_array($draft)
                || !is_int($draft['contract'] ?? null) || !is_int($draft['sheet'] ?? null) || !is_int($draft['item'] ?? null)
                || $draft['contract']<1 || $draft['sheet']<0 || $draft['item']<0
                || !is_string($draft['operation'] ?? null) || !isset(self::FIELDS[$draft['operation']])
                || !is_string($draft['context'] ?? null) || preg_match('/^[a-f0-9]{32}$/D',$draft['context'])!==1
                || !is_int($draft['created'] ?? null) || $draft['created']<time()-self::TTL
                || ($draft['revision'] ?? null)!==null && (!is_int($draft['revision']) || $draft['revision']<1 || $draft['revision']>1000000000)
                || !is_array($draft['fields'] ?? null)) { continue; }
            $draft['fields']=self::fields($draft['operation'],$draft['fields']);
            $draft['revision']=$draft['revision'] ?? null;
            $draft['message']=is_string($draft['message'] ?? null) && mb_check_encoding($draft['message'],'UTF-8') ? mb_substr($draft['message'],0,500,'UTF-8') : '';
            $_SESSION['contract_form_recovery'][$id]=$draft;
        }
    }
}
