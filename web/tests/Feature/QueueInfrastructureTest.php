<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_queue_and_failed_job_tables_remain_without_batching_table(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertFalse(Schema::hasTable('job_batches'));
        $this->assertSame('jobs', config('queue.connections.database.table'));
        $this->assertSame('failed_jobs', config('queue.failed.table'));
    }
}
