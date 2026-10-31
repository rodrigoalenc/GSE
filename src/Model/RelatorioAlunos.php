<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Core/Model.php';

final class RelatorioAlunos extends Model
{
    private DvaStatus $status;

    public function __construct(?DvaStatus $status = null)
    {
        parent::__construct();
        $this->status = $status ?? new DvaStatus();
    }

    /** @return list<array<string,mixed>> */
    public function classes(): array
    {
        return self::$pdo->query('SELECT id,nome_turma,ano_letivo FROM turmas ORDER BY nome_turma,id')->fetchAll();
    }

    /** @param array<string,mixed> $input
     *  @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,filters:array<string,string>,class_filter_label:string} */
    public function query(array $input, int $page = 1, bool $all = false, int $exportLimit = 10000): array
    {
        foreach (['turma','dva','ativo'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) { throw new DomainException('Filtro inválido.'); }
        }
        $class = (string)($input['turma'] ?? '');
        $dva = (string)($input['dva'] ?? '');
        $active = (string)($input['ativo'] ?? '1');
        if ($class !== '' && (filter_var($class,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) === false)) { throw new DomainException('Turma inválida.'); }
        if ($dva !== '' && !in_array($dva,DvaStatus::ALL,true)) { throw new DomainException('Situação da DVA inválida.'); }
        if (!in_array($active,['0','1','todos'],true)) { throw new DomainException('Situação do aluno inválida.'); }
        $classFilterLabel='Todas';
        if ($class !== '') {
            $verify=self::$pdo->prepare('SELECT nome_turma,ano_letivo FROM turmas WHERE id=?'); $verify->execute([$class]);
            $selectedClass=$verify->fetch();
            if ($selectedClass===false) { throw new DomainException('Turma não encontrada.'); }
            $classFilterLabel=self::classLabel($selectedClass['nome_turma'],$selectedClass['ano_letivo']);
        }
        $where=[]; $params=[];
        if ($class !== '') { $where[]='a.id_turma=:class'; $params['class']=$class; }
        if ($active !== 'todos') { $where[]='a.ativo=:active'; $params['active']=$active; }
        if ($dva !== '') {
            $filter = $this->status->filter($dva);
            $where[] = $filter['sql'];
            $params = array_merge($params, $filter['params']);
        }
        $from=' FROM alunos a LEFT JOIN turmas t ON t.id=a.id_turma LEFT JOIN dvas d ON d.id_aluno=a.id AND d.ativo=1';
        $suffix=$where === [] ? '' : ' WHERE '.implode(' AND ',$where);
        $count=self::$pdo->prepare('SELECT COUNT(*)'.$from.$suffix); $count->execute($params); $total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/25)); $page=max(1,min($page,$pages));
        $exportLimit = max(1, min(10000, $exportLimit));
        if ($all && $total>$exportLimit) { throw new DomainException('Exportação limitada a '.number_format($exportLimit, 0, ',', '.').' alunos. Aplique filtros mais específicos.'); }
        $sql='SELECT a.id,a.nome_completo,a.data_nascimento,a.ativo,t.nome_turma,t.ano_letivo,d.data_vencimento'.$from.$suffix.' ORDER BY t.nome_turma,a.nome_normalizado,a.id';
        if (!$all) { $sql.=' LIMIT 25 OFFSET '.(($page-1)*25); }
        else { $sql.=' LIMIT '.($exportLimit + 1); }
        $statement=self::$pdo->prepare($sql); $statement->execute($params); $items=$statement->fetchAll();
        if ($all && count($items)>$exportLimit) { throw new DomainException('A base foi alterada. Aplique filtros mais específicos e gere o relatório novamente.'); }
        foreach ($items as &$item) {
            $item['dva_status']=$this->status->classify($item['data_vencimento']);
            if ($item['dva_status']===DvaStatus::SEM_DVA && $item['data_vencimento']!==null) {
                $item['dva_data_invalida']=true;
                $item['data_vencimento']=null;
            }
        }
        unset($item);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>$pages,'filters'=>['turma'=>$class,'dva'=>$dva,'ativo'=>$active],'class_filter_label'=>$classFilterLabel];
    }

    public static function classLabel(?string $name, int|string|null $year): string
    {
        if ($name===null) { return 'Sem turma'; }
        return $name.' — '.($year===null || $year==='' ? 'Ano não informado' : (string)$year);
    }

    public static function csvCell(?string $value): string
    {
        $value=(string)$value;
        if (preg_match('/^[\s\p{C}]*[=+@\-]/u',$value) === 1) {
            $value="'".(preg_replace('/^[\s\p{C}]+/u','',$value) ?? $value);
        }
        return $value;
    }

    public static function displayDate(?string $value): string
    {
        if ($value === null || $value === '') { return ''; }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date!==false && $date->format('Y-m-d')===$value ? $date->format('d/m/Y') : '';
    }
}
