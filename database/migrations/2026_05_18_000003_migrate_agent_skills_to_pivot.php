<?php

use App\Models\Skill;
use App\Models\WarRoomAgentConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $agents = WarRoomAgentConfig::all();

        foreach ($agents as $agent) {
            $skillLabels = $agent->getRawOriginal('skills');
            $skills = json_decode($skillLabels, true) ?? [];

            if (empty($skills)) {
                continue;
            }

            foreach ($skills as $skillEntry) {
                $label = is_array($skillEntry) ? ($skillEntry['skill'] ?? '') : $skillEntry;

                if (! is_string($label) || blank($label)) {
                    continue;
                }

                $skill = Skill::where('display_name', $label)->first()
                    ?? Skill::where('name', str()->slug($label))->first();

                if (! $skill) {
                    $skill = Skill::create([
                        'name' => str()->slug($label),
                        'display_name' => $label,
                        'description' => "Auto-created from agent skill: {$label}",
                        'domain' => 'custom',
                        'is_active' => true,
                        'source' => 'migrated',
                        'sort_order' => 999,
                    ]);
                }

                $agent->skillRecords()->syncWithoutDetaching([$skill->id]);
            }
        }
    }

    public function down(): void
    {
        DB::table('agent_skill')->truncate();
        Skill::where('source', 'migrated')->delete();
    }
};
