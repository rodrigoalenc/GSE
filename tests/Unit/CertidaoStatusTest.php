<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
final class CertidaoStatusTest extends TestCase
{
    public function testCivilDatesBoundariesAndInvalidDates(): void
    {
        $status=new \CertidaoStatus(new \DateTimeImmutable('2026-09-16T12:00:00Z'),15);
        foreach (['2026-09-15'=>'vencida','2026-09-16'=>'vence_hoje','2026-09-17'=>'a_vencer','2026-10-01'=>'a_vencer','2026-10-02'=>'vigente','2026-02-30'=>'pendente',''=>'pendente'] as $date=>$expected) { $this->assertSame($expected,$status->classify($date)); }
        $this->assertTrue(\CertidaoStatus::validDate('2024-02-29')); $this->assertFalse(\CertidaoStatus::validDate('0000-01-01'));
    }
    public function testConfiguredTimezoneAndWarningDaysValidation(): void
    {
        $before=$_ENV['APP_TIMEZONE'] ?? null; $_ENV['APP_TIMEZONE']='America/Cuiaba';
        try { $status=new \CertidaoStatus(new \DateTimeImmutable('2026-09-16T01:00:00Z'),1); $this->assertSame('2026-09-15',$status->today()); $this->assertSame('2026-09-16',$status->limit()); }
        finally { if ($before===null) { unset($_ENV['APP_TIMEZONE']); } else { $_ENV['APP_TIMEZONE']=$before; } }
        $this->expectException(\RuntimeException::class); new \CertidaoStatus(null,0);
    }
    public function testDeadlineUsesCivilDateAndHandlesInvalidLegacyDate(): void
    {
        $status=new \CertidaoStatus(new \DateTimeImmutable('2026-09-16T12:00:00Z'),15);
        $this->assertSame('Vencida há 1 dia',$status->deadline('2026-09-15'));
        $this->assertSame('Vence hoje',$status->deadline('2026-09-16'));
        $this->assertSame('Vence em 15 dias',$status->deadline('2026-10-01'));
        $this->assertSame('Prazo pendente de revisão',$status->deadline('2026-02-30'));
    }
}
