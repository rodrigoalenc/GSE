<?php

declare(strict_types=1);

final class FeriadosAgenda
{
    /** @return list<array{data:string,titulo:string,tipo:string}> */
    public function listarPorAno(int $ano): array
    {
        $nacionais = [
            '01-01' => 'Confraternização Universal', '04-21' => 'Tiradentes', '05-01' => 'Dia do Trabalho',
            '09-07' => 'Independência do Brasil', '10-12' => 'Nossa Senhora Aparecida', '11-02' => 'Finados',
            '11-15' => 'Proclamação da República', '12-25' => 'Natal',
        ];
        if ($ano >= 2024) {
            $nacionais['11-20'] = 'Dia Nacional de Zumbi e da Consciência Negra';
        }
        $municipais = [
            '05-25' => 'Feriado Municipal de Vicentina', '06-20' => 'Aniversário de Vicentina',
            '09-12' => 'Morte do Padre Roberto', '10-01' => 'Santa Terezinha', '12-08' => 'Morte do Padre José Daniel',
        ];
        $pascoa = $this->pascoa($ano);
        $nacionais[$pascoa->modify('-2 days')->format('m-d')] = 'Paixão de Cristo';
        $nacionais[$pascoa->format('m-d')] = 'Páscoa';
        $facultativos = [
            $pascoa->modify('-47 days')->format('m-d') => 'Carnaval',
            $pascoa->modify('+60 days')->format('m-d') => 'Corpus Christi',
        ];

        $resultado = [];
        foreach (['Nacional' => $nacionais, 'Vicentina' => $municipais, 'Ponto facultativo' => $facultativos] as $tipo => $dias) {
            foreach ($dias as $dia => $titulo) {
                $resultado[] = ['data' => sprintf('%04d-%s', $ano, $dia), 'titulo' => $titulo, 'tipo' => $tipo];
            }
        }
        usort($resultado, static fn (array $a, array $b): int => [$a['data'], $a['titulo']] <=> [$b['data'], $b['titulo']]);

        return $resultado;
    }

    /** @return list<array{data:string,titulo:string,tipo:string}> */
    public function listarProximos(DateTimeImmutable $hoje, int $dias): array
    {
        $limite = $hoje->modify('+' . $dias . ' days');
        $inicioIso = $hoje->format('Y-m-d');
        $fimIso = $limite->format('Y-m-d');
        $datas = [];
        foreach (range((int) $hoje->format('Y'), (int) $limite->format('Y')) as $ano) {
            foreach ($this->listarPorAno($ano) as $feriado) {
                if ($feriado['data'] >= $inicioIso && $feriado['data'] <= $fimIso) {
                    $datas[] = $feriado;
                }
            }
        }

        return $datas;
    }

    private function pascoa(int $ano): DateTimeImmutable
    {
        // Computus gregoriano. A data é civil e independe do fuso do servidor.
        $century = intdiv($ano, 100);
        $cycle = $ano % 19;
        $epact = (19 * $cycle + $century - intdiv($century, 4)
            - intdiv($century - intdiv($century + 8, 25) + 1, 3) + 15) % 30;
        $yearOfCentury = $ano % 100;
        $weekday = (32 + 2 * ($century % 4) + 2 * intdiv($yearOfCentury, 4) - $epact - $yearOfCentury % 4) % 7;
        $dayIndex = $epact + $weekday - 7 * intdiv($cycle + 11 * $epact + 22 * $weekday, 451) + 114;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, intdiv($dayIndex, 31), $dayIndex % 31 + 1));
    }
}
