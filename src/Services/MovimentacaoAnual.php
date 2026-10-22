<?php
declare(strict_types=1);

/** Annual orchestration shares the existing transactional movement and history rules. */
trait MovimentacaoAnual
{
    /** @return array<string,mixed> */
    public function conferenciaTurma(int $year, int $class): array
    {
        $this->ensure($this->validYear($year), 'invalid_year');
        $classes = array_column($this->turmas(), null, 'id');
        $this->ensure(isset($classes[$class]) && (int)$classes[$class]['serie'] === 12, 'invalid_class');
        $students = array_values(array_filter($this->conferencia($year)['alunos'], static fn(array $s): bool => (int)$s['id_turma'] === $class));
        return ['ano'=>$year, 'id_turma'=>$class, 'alunos'=>$students,
            'fingerprint'=>$this->fingerprint([$year,$class,$students])];
    }

    /** Validate a senior selection inside the archive transaction, before any mutation.
     * @param array<string,mixed> $context
     * @param array<mixed> $selected
     * @return array<string,mixed>
     */
    public function validarEncerramento(array $context, array $selected): array
    {
        $current = $this->conferenciaTurma((int)($context['ano'] ?? 0),(int)($context['id_turma'] ?? 0));
        $this->ensure(hash_equals($current['fingerprint'], (string)($context['fingerprint'] ?? '')), 'preview_changed');
        $ids = array_map('intval',array_column($current['alunos'],'id'));
        $this->ensure(count(array_unique($selected)) === count($selected) && array_diff(array_map('intval',$selected),$ids) === [],'invalid_selection');
        $current['retidos'] = array_values(array_diff($ids,array_map('intval',$selected)));
        return $current;
    }

    /** Called exclusively within the same domain transaction as validation and archiving.
     * @param array<string,mixed> $closure
     */
    public function registrarEncerramento(PDO $pdo, array $closure, int $actor): void
    {
        $this->admin($actor);
        $retained = array_map('intval',array_column($this->conferenciaTurma($closure['ano'],$closure['id_turma'])['alunos'],'id'));
        sort($retained);
        $expected=$closure['retidos']; sort($expected);
        $this->ensure($retained === $expected,'preview_changed');
        $pdo->prepare('INSERT INTO alunos_encerramento_turmas(ano,id_turma,retidos_json,confirmado_por) VALUES(?,?,?,?)
            ON CONFLICT(ano,id_turma) DO UPDATE SET retidos_json=excluded.retidos_json,
            confirmado_por=excluded.confirmado_por,confirmado_em=CURRENT_TIMESTAMP')
            ->execute([$closure['ano'],$closure['id_turma'],json_encode($retained,JSON_THROW_ON_ERROR),$actor]);
        AuditLogger::recordRequired($pdo,'student.senior_class_closed',AuditLogger::SUCCESS,$actor,null,
            sprintf('Referência %d: envio dos concluintes concluído; %d reprovado(s) permanecem ativos e fora da progressão.',$closure['ano'],count($retained)),
            'class',$closure['id_turma']);
    }

    public function manterTurma(int $year,int $class,string $fingerprint,int $actor): bool
    {
        return $this->write(function(PDO $pdo) use($year,$class,$fingerprint,$actor): bool {
            $this->admin($actor);
            $closure=$this->validarEncerramento(['ano'=>$year,'id_turma'=>$class,'fingerprint'=>$fingerprint],[]);
            $this->registrarEncerramento($pdo,$closure,$actor);
            return true;
        });
    }

    /** @return array<string,mixed> */
    public function estadoAnual(int $year): array
    {
        $review=$this->conferencia($year);
        $query=self::$pdo->prepare('SELECT * FROM alunos_encerramento_turmas WHERE ano=?');
        $query->execute([$year]);
        $closed=array_column($query->fetchAll(),null,'id_turma');
        $pending=[];
        foreach($review['alunos'] as $student){
            $class=(int)$student['id_turma'];
            $retained=isset($closed[$class]) ? json_decode($closed[$class]['retidos_json'],true,512,JSON_THROW_ON_ERROR) : [];
            if(!in_array((int)$student['id'],$retained,true)) $pending[$class]=true;
        }
        $cycle=self::$pdo->prepare('SELECT * FROM alunos_progressao_anos WHERE ano=?');
        $cycle->execute([$year]);
        return ['ano'=>$year,'liberada'=>$review['pendentes']===0 && $pending===[],
            'pendentes'=>$review['pendentes'],'turmas_pendentes'=>array_keys($pending),
            'retidos'=>count($review['alunos']),'concluida'=>$cycle->fetch()!==false,
            'fingerprint'=>$this->fingerprint([$review['fingerprint'],$closed])];
    }

    /** @return array<int,int> */
    public function destinosSalvos(): array
    {
        return array_map('intval',self::$pdo->query('SELECT origem_id,destino_id FROM turmas_progressao_destinos')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @param array<mixed> $ids
     * @return list<array<string,mixed>>
     */
    public function selecionados(array $ids): array
    {
        $this->ensure(array_is_list($ids) && count($ids)>0 && count($ids)<=self::MAX_ALUNOS,'invalid_selection');
        foreach($ids as $id) $this->ensure((is_string($id)||is_int($id)) && filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false,'invalid_selection');
        $ids=array_map('intval',$ids);
        $this->ensure(count(array_unique($ids))===count($ids),'invalid_selection');
        $query=self::$pdo->prepare('SELECT a.id,a.nome_completo,a.id_turma,t.nome_turma,s.serie FROM alunos a
            LEFT JOIN turmas t ON t.id=a.id_turma LEFT JOIN turmas_series s ON s.id_turma=a.id_turma
            WHERE a.ativo=1 AND a.id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY a.nome_normalizado,a.id');
        $query->execute($ids); $students=array_map($this->identificarSerie(...),$query->fetchAll());
        $this->ensure(count($students)===count($ids),'inactive_student');
        return $students;
    }

    /** @param array<mixed> $mapping
     * @param array<mixed> $keep
     * @return array<string,mixed>|false
     */
    public function previewAnual(array $mapping,int $year,array $keep=[],bool $bulk=false): array|false
    {
        $this->lastErrorCode=null;
        try { return $this->prepararAnual($mapping,$year,$keep,$bulk); }
        catch(DomainException $e){$this->lastErrorCode=$e->getMessage();return false;}
    }

    /** @param array<mixed> $mapping
     * @param array<mixed> $keep
     * @return array<string,mixed>
     */
    private function prepararAnual(array $mapping,int $year,array $keep=[],bool $bulk=false): array
    {
        $mapping=$this->normalizarDestinos($mapping);
        $this->ensure($this->validYear($year),'invalid_year');
        $state=$this->estadoAnual($year);
        $this->ensure(!$state['concluida'],'year_completed');
        $this->ensure($state['liberada'],'review_required');
        if($bulk){
            $plan=$this->prepararTodas($mapping,$keep,$year,true);
            $students=[]; $summary=[]; $used=[];
            $classes=array_column($this->turmas(),null,'id');
            foreach($plan['grupos'] as $group){
                $source=(int)$group['alunos'][0]['id_turma'];
                foreach($group['alunos'] as $student){$student['destino']=$group['destino'];$students[]=$student;}
                $used[$source]=(int)$group['destino']['id'];
                if(!isset($summary[$source])) $summary[$source]=['origem'=>$classes[$source],'destino'=>$group['destino'],'total'=>0];
                $summary[$source]['total']+=count($group['ids']);
            }
            $summary=array_values($summary);
            $annual=$this->planoAutomatico($students,$used,$year,'anual',$summary,$state['fingerprint']);
            $plan['fingerprint']=$this->fingerprint([$plan['fingerprint'],$annual['fingerprint']]);
            return $plan+['fluxo'=>'anual','alunos'=>$students,'mapa'=>$mapping,'resumo'=>$summary,'destinos_usados'=>$used];
        }
        $groups=$this->gruposAnuais($year);
        $this->ensure($groups!==[],'no_students');
        $classes=array_column($this->turmas(),null,'id'); $saved=$this->destinosSalvos();
        $students=[]; $used=[]; $summary=[];
        foreach($groups as $source=>$ids){
            $candidates=array_filter($classes,static fn(array $c): bool => (int)$c['ativo']===1 && (int)$c['serie']===(int)$classes[$source]['serie']+1);
            $automatic=$this->destinoProgressao((int)$source,$year);
            $target=(int)($mapping[$source] ?? $automatic['id'] ?? $saved[$source] ?? 0);
            $this->ensure(isset($candidates[$target]),'destination_required');
            $plan=$this->prepare($ids,$target,'progressao',$year,true);
            foreach($plan['alunos'] as $student){ $student['destino']=$plan['destino']; $students[]=$student; }
            $used[$source]=$target;
            $summary[]=['origem'=>$classes[$source],'destino'=>$plan['destino'],'total'=>count($ids)];
        }
        return $this->planoAutomatico($students,$used,$year,'anual',$summary,$state['fingerprint']);
    }

    /** @return array<int,list<int>> */
    private function gruposAnuais(int $year): array
    {
        $groups=[];
        foreach($this->alunosMovimentacao($year) as $student){
            if($student['serie']!==null && (int)$student['serie']<12 && (int)$student['turma_ativa']===1
                && (int)$student['no_passivo']===0 && (int)$student['saldo']<1){
                $groups[(int)$student['id_turma']][]=(int)$student['id'];
            }
        }
        return $groups;
    }

    /** @return array<int,int> */
    public function contagensAnuais(int $year): array
    {
        return array_map('count',$this->gruposAnuais($year));
    }

    /** @param array<mixed> $ids
     * @param array<mixed> $mapping
     * @return array<string,mixed>|false
     */
    public function previewRegressao(array $ids,array $mapping,int $year): array|false
    {
        $this->lastErrorCode=null;
        try { return $this->prepararRegressao($ids,$mapping,$year); }
        catch(DomainException $e){$this->lastErrorCode=$e->getMessage();return false;}
    }

    /** @param array<mixed> $ids
     * @param array<mixed> $mapping
     * @return array<string,mixed>
     */
    private function prepararRegressao(array $ids,array $mapping,int $year): array
    {
        $mapping=$this->normalizarDestinos($mapping);
        $students=$this->selecionados($ids); $classes=array_column($this->turmas(),null,'id');
        $rows=[];
        foreach($students as $student){
            $source=(int)$student['id_turma'];
            $candidates=array_filter($classes,static fn(array $c): bool => (int)$c['ativo']===1 && (int)$c['serie']===(int)$student['serie']-1);
            $history=$this->historico((int)$student['id']); $previous=$history[0] ?? null;
            $original=$previous!==null && (int)$previous['destino_id']===$source ? (int)$previous['origem_id'] : 0;
            $target=(int)($mapping[$source] ?? (isset($candidates[$original]) ? $original : (count($candidates)===1 ? array_key_first($candidates) : 0)));
            $this->ensure(isset($candidates[$target]),'destination_required');
            $plan=$this->prepare([(int)$student['id']],$target,'regressao',$year);
            $row=$plan['alunos'][0]; $row['destino']=$plan['destino']; $rows[]=$row;
        }
        return $this->planoAutomatico($rows,$mapping,$year,'regressao_selecao',[],null);
    }

    /** @param list<array<string,mixed>> $students
     * @param array<mixed> $mapping
     * @param list<array<string,mixed>> $summary
     * @return array<string,mixed>
     */
    private function planoAutomatico(array $students,array $mapping,int $year,string $flow,array $summary,?string $state): array
    {
        return ['fluxo'=>$flow,'tipo'=>$flow==='anual'?'progressao':'regressao','ano'=>$year,
            'ids'=>array_map('intval',array_column($students,'id')),'alunos'=>$students,'mapa'=>$mapping,'resumo'=>$summary,
            'fingerprint'=>$this->fingerprint([$flow,$year,$students,$mapping,$state])];
    }

    /** @param array<mixed> $mapping
     * @return array<int,int>
     */
    private function normalizarDestinos(array $mapping): array
    {
        $this->ensure(count($mapping)<=1000,'invalid_class');
        $valid=[];
        foreach($mapping as $source=>$target){
            if($target==='') continue;
            $this->ensure(filter_var($source,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false
                && (is_int($target)||is_string($target)) && filter_var($target,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false,'invalid_class');
            $valid[(int)$source]=(int)$target;
        }
        return $valid;
    }

    /** @param array<string,mixed> $plan */
    public function confirmarAutomatico(array $plan,int $actor,string $token): int|false
    {
        return $this->write(function(PDO $pdo) use($plan,$actor,$token): int|false {
            $this->admin($actor);
            $this->ensure(in_array($plan['fluxo'] ?? '',['anual','regressao_selecao'],true),'invalid_operation');
            return RequestReceipt::run($pdo,$actor,'student.'.$plan['fluxo'],$token,[$plan],function() use($pdo,$plan,$actor): int {
                $current=$plan['fluxo']==='anual' ? $this->prepararAnual($plan['mapa'],$plan['ano'],$plan['manter'] ?? [],!empty($plan['todas_turmas'])) : $this->prepararRegressao($plan['ids'],$plan['mapa'],$plan['ano']);
                $this->ensure(hash_equals($current['fingerprint'],$plan['fingerprint']),'preview_changed');
                $pdo->prepare('INSERT INTO alunos_movimentacao_lotes(tipo,ano,realizado_por) VALUES(?,?,?)')->execute([$current['tipo'],$current['ano'],$actor]);
                $batch=(int)$pdo->lastInsertId();
                $insert=$pdo->prepare('INSERT INTO alunos_movimentacoes(lote_id,aluno_id,origem_id,destino_id,origem_nome,destino_nome,origem_ano,destino_ano,origem_serie,destino_serie) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $update=$pdo->prepare('UPDATE alunos SET id_turma=?,atualizado_em=? WHERE id=? AND id_turma=? AND ativo=1');
                foreach($current['alunos'] as $student){
                    $target=$student['destino'];
                    $update->execute([$target['id'],gmdate('Y-m-d H:i:s'),$student['id'],$student['id_turma']]);
                    $this->ensure($update->rowCount()===1,'preview_changed');
                    $insert->execute([$batch,$student['id'],$student['id_turma'],$target['id'],$student['nome_turma'],$target['nome_turma'],$student['ano_letivo'],$target['ano_letivo'],$student['serie'],$target['serie']]);
                    AuditLogger::recordRequired($pdo,$current['tipo']==='progressao'?'student.progressed':'student.regressed',AuditLogger::SUCCESS,$actor,null,
                        sprintf('Referência %d: %s → %s; lote %d.',$current['ano'],$student['nome_turma'],$target['nome_turma'],$batch),'student',(int)$student['id']);
                }
                if($current['fluxo']==='anual'){
                    $pdo->prepare('INSERT INTO alunos_progressao_anos(ano,lote_id,realizado_por) VALUES(?,?,?)')->execute([$current['ano'],$batch,$actor]);
                    foreach(($current['destinos_usados'] ?? $current['mapa']) as $source=>$target) $pdo->prepare('INSERT INTO turmas_progressao_destinos(origem_id,destino_id) VALUES(?,?) ON CONFLICT(origem_id) DO UPDATE SET destino_id=excluded.destino_id')->execute([$source,$target]);
                    AuditLogger::recordRequired($pdo,'student.year_progressed',AuditLogger::SUCCESS,$actor,null,
                        sprintf('Progressão geral de %d: %d aluno(s), %d turma(s); lote %d. Reprovados do 3º ano permaneceram ativos.',$current['ano'],count($current['ids']),count($current['resumo']),$batch),'student_progression',$batch);
                }
                return $batch;
            });
        });
    }
}
