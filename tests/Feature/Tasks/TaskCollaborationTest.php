<?php

namespace Tests\Feature\Tasks;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Membership;
use App\Models\Task;
use App\Models\TaskLink;
use App\Services\CommentService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Comments, mentions, attachments, links and watchers. */
class TaskCollaborationTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    private Task $task; // ECP-1, reported by the PM

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
        $this->task = $this->task($this->ecp, $this->pm);
        Storage::fake('local');
    }

    private function comment(string $body, ?Membership $by = null, array $extra = [], string $key = 'ECP-1')
    {
        return $this->actingAsMember($by ?? $this->tlA)->post("/tasks/{$key}/comments", ['body' => $body] + $extra);
    }

    private function mention(Membership $m): string
    {
        return "@[{$m->user->name}](m:{$m->id})";
    }

    // ---- comments --------------------------------------------------------------------------

    public function test_a_comment_can_be_added(): void
    {
        $this->comment('Looks good to me')->assertSessionHasNoErrors();

        $c = Comment::sole();
        $this->assertSame(['Looks good to me', $this->tlA->id, $this->task->id], [$c->body, $c->membership_id, $c->task_id]);
        $this->assertNull($c->edited_at);
        $this->assertSame('commented', $this->task->activities()->first()->event);
    }

    public function test_commenting_makes_you_a_watcher(): void
    {
        $this->comment('Hi');

        $this->assertContains($this->tlA->id, $this->task->watchers()->pluck('memberships.id')->all());
    }

    public function test_comments_can_be_internal_or_public(): void
    {
        $this->comment('Team only', null, ['is_internal' => true]);
        $this->comment('For everyone', null, ['is_internal' => false]);

        $this->assertSame([true, false], Comment::orderBy('id')->pluck('is_internal')->map(fn ($v) => (bool) $v)->all());
    }

    public function test_the_body_is_validated(): void
    {
        $this->comment('')->assertSessionHasErrors('body');
        $this->comment('   ')->assertSessionHasErrors('body');
        $this->comment(str_repeat('x', CommentService::MAX_LENGTH + 1))->assertSessionHasErrors('body');
        $this->assertSame(0, Comment::count());
    }

    public function test_only_people_who_can_see_and_comment_can_comment(): void
    {
        $this->comment('x', $this->dev3)->assertForbidden(); // other team
        $this->comment('x', $this->stakeholder)->assertForbidden(); // not on the project
        app(PermissionService::class)->override($this->tlA, 'comment.create', 'revoke');
        $this->comment('x')->assertForbidden();
        $this->assertSame(0, Comment::count());
    }

    public function test_an_external_client_can_only_write_public_comments(): void
    {
        $own = $this->task($this->ecp, $this->client);

        $this->comment('Please hurry', $this->client, ['is_internal' => true], 'ECP-2')->assertSessionHasNoErrors();

        $this->assertFalse((bool) Comment::sole()->is_internal);
        $this->assertNotNull($own);
    }

    public function test_authors_can_edit_their_own_comments_only(): void
    {
        $this->comment('First draft');
        $c = Comment::sole();

        $this->actingAsMember($this->tlA)->put("/comments/{$c->id}", ['body' => 'Final text', 'is_internal' => true])->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('Final text', $c->body);
        $this->assertTrue($c->is_internal);
        $this->assertNotNull($c->edited_at);

        $this->actingAsMember($this->dev1)->put("/comments/{$c->id}", ['body' => 'Hijacked'])->assertForbidden();
        $this->actingAsMember($this->pm)->put("/comments/{$c->id}", ['body' => 'Hijacked'])->assertForbidden(); // even a manager cannot edit someone's words
        $this->assertSame('Final text', $c->fresh()->body);
    }

    public function test_authors_and_moderators_can_delete(): void
    {
        $this->comment('Mine', $this->dev1);
        $mine = Comment::sole();

        $this->actingAsMember($this->tlA)->delete("/comments/{$mine->id}")->assertForbidden(); // a lead has no delete_any
        $this->actingAsMember($this->dev1)->delete("/comments/{$mine->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($mine);

        $this->comment('Another', $this->dev1);
        $this->actingAsMember($this->pm)->delete('/comments/'.Comment::latest('id')->first()->id)->assertSessionHasNoErrors(); // PM role has comment.delete_any
    }

    public function test_comments_of_invisible_tasks_cannot_be_reached(): void
    {
        $this->comment('Secret', $this->pm);
        $c = Comment::sole();
        $other = $this->task($this->oth, $this->tlB);
        $c->forceFill(['task_id' => $other->id])->save();

        $this->actingAsMember($this->dev1)->put("/comments/{$c->id}", ['body' => 'x'])->assertNotFound();
        $this->actingAsMember($this->dev1)->delete("/comments/{$c->id}")->assertNotFound();
    }

    // ---- mentions -------------------------------------------------------------------------

    public function test_mentions_are_recorded_and_the_mentioned_person_follows_the_task(): void
    {
        $this->comment("Can you check this {$this->mention($this->dev1)}?");

        $c = Comment::sole();
        $this->assertSame([$this->dev1->id], $c->mentions()->pluck('memberships.id')->all());
        $this->assertContains($this->dev1->id, $this->task->watchers()->pluck('memberships.id')->all());
    }

    public function test_only_people_who_can_already_see_the_task_count_as_mentioned(): void
    {
        // Dev3 cannot see ECP tasks: naming them must not open the task to them.
        $this->comment("FYI {$this->mention($this->dev3)} and {$this->mention($this->dev1)}");

        $this->assertSame([$this->dev1->id], Comment::sole()->mentions()->pluck('memberships.id')->all());
        $this->assertNotContains($this->dev3->id, $this->task->watchers()->pluck('memberships.id')->all());
        $this->assertNotContains($this->task->id, $this->visibleIds($this->dev3));
    }

    public function test_internal_comments_never_mention_external_people(): void
    {
        $this->comment("Between us {$this->mention($this->client)}", null, ['is_internal' => true]);
        $this->assertSame([], Comment::sole()->mentions()->pluck('memberships.id')->all());
    }

    public function test_mentions_of_people_who_left_or_in_other_companies_are_ignored(): void
    {
        [$other] = $this->makeCompany();
        $foreigner = $this->makeMember($other, 'developer');
        $this->comment("{$this->mention($foreigner)} {$this->mention($this->dev1)}");

        $this->assertSame([$this->dev1->id], Comment::sole()->mentions()->pluck('memberships.id')->all());
    }

    public function test_editing_a_comment_updates_its_mentions(): void
    {
        $this->comment("Hi {$this->mention($this->dev1)}");
        $c = Comment::sole();

        $this->actingAsMember($this->tlA)->put("/comments/{$c->id}", ['body' => "Hi {$this->mention($this->pm)}"]);

        $this->assertSame([$this->pm->id], $c->mentions()->pluck('memberships.id')->all());
    }

    public function test_the_mention_syntax_is_parsed(): void
    {
        $this->assertSame([7 => 'Rahim Uddin', 9 => 'Ann'], CommentService::mentionedIds('Hi @[Rahim Uddin](m:7) and @[Ann](m:9), not @[x](n:3) or @plain'));
    }

    // ---- attachments ----------------------------------------------------------------------

    private function upload(UploadedFile $file, ?Membership $by = null, string $key = 'ECP-1')
    {
        return $this->actingAsMember($by ?? $this->tlA)->post("/tasks/{$key}/attachments", ['files' => [$file]]);
    }

    public function test_a_file_can_be_uploaded_and_is_stored_privately(): void
    {
        $this->upload(UploadedFile::fake()->create('spec.pdf', 200, 'application/pdf'))->assertSessionHasNoErrors();

        $a = Attachment::sole();
        $this->assertSame(['spec.pdf', Task::class, $this->task->id, $this->tlA->id], [$a->original_name, $a->attachable_type, $a->attachable_id, $a->membership_id]);
        $this->assertSame('local', $a->disk);
        $this->assertStringStartsWith("attachments/{$this->org->id}/{$this->task->id}/", $a->path);
        $this->assertStringEndsWith('.pdf', $a->path);
        $this->assertStringNotContainsString('spec', $a->path, 'the stored name is random');
        Storage::disk('local')->assertExists($a->path);
        $this->assertSame('attachment_added', $this->task->activities()->first()->event);
    }

    public function test_dangerous_or_oversized_files_are_refused(): void
    {
        foreach (['run.exe', 'page.html', 'logo.svg', 'script.php', 'archive.tar.gz'] as $name) {
            $this->upload(UploadedFile::fake()->create($name, 10))->assertSessionHasErrors('file');
        }
        $this->upload(UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'))->assertSessionHasErrors('file');
        $this->assertSame(0, Attachment::count());

        $this->upload(UploadedFile::fake()->create('limit.pdf', 10240, 'application/pdf'))->assertSessionHasNoErrors();
    }

    public function test_a_task_holds_a_limited_number_of_files(): void
    {
        for ($i = 0; $i < 25; $i++) {
            Attachment::create(['organization_id' => $this->org->id, 'attachable_type' => Task::class, 'attachable_id' => $this->task->id, 'membership_id' => $this->pm->id, 'path' => "x/{$i}.pdf", 'original_name' => "{$i}.pdf"]);
        }

        $this->upload(UploadedFile::fake()->create('one-too-many.pdf', 10, 'application/pdf'))->assertSessionHasErrors('file');
    }

    public function test_files_download_only_for_people_who_can_see_the_task(): void
    {
        $this->upload(UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf'));
        $a = Attachment::sole();

        $this->actingAsMember($this->dev1)->get("/attachments/{$a->id}")->assertOk()->assertDownload('spec.pdf');
        $this->actingAsMember($this->dev3)->get("/attachments/{$a->id}")->assertNotFound();
        $this->actingAsMember($this->client)->get("/attachments/{$a->id}")->assertNotFound();
        auth()->logout();
        $this->get("/attachments/{$a->id}")->assertRedirect(route('login'));
    }

    public function test_images_preview_inline_but_other_files_never_do(): void
    {
        $this->upload(UploadedFile::fake()->image('shot.png'));
        $this->upload(UploadedFile::fake()->create('notes.txt', 1, 'text/plain'));
        [$image, $text] = [Attachment::where('original_name', 'shot.png')->first(), Attachment::where('original_name', 'notes.txt')->first()];

        $inline = $this->actingAsMember($this->tlA)->get("/attachments/{$image->id}?inline=1");
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));

        $forced = $this->actingAsMember($this->tlA)->get("/attachments/{$text->id}?inline=1");
        $this->assertStringStartsWith('attachment', $forced->headers->get('Content-Disposition'));
    }

    public function test_attachments_can_be_deleted_by_their_uploader_or_a_moderator(): void
    {
        $this->upload(UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), $this->dev1);
        $a = Attachment::sole();

        $this->actingAsMember($this->tlA)->delete("/attachments/{$a->id}")->assertForbidden();
        $this->actingAsMember($this->dev1)->delete("/attachments/{$a->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($a);
        $this->assertSame('attachment_removed', $this->task->activities()->first()->event);

        $this->upload(UploadedFile::fake()->create('b.pdf', 5, 'application/pdf'), $this->dev1);
        $this->actingAsMember($this->pm)->delete('/attachments/'.Attachment::latest('id')->first()->id)->assertSessionHasNoErrors();
    }

    public function test_uploading_needs_the_permission(): void
    {
        app(PermissionService::class)->override($this->tlA, 'attachment.upload', 'revoke');

        $this->upload(UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'))->assertForbidden();
    }

    // ---- links ----------------------------------------------------------------------------

    private function link(string $type, Task $target, ?Membership $by = null, string $key = 'ECP-1')
    {
        return $this->actingAsMember($by ?? $this->tlA)->post("/tasks/{$key}/links", ['type' => $type, 'target_id' => $target->id]);
    }

    public function test_tasks_can_be_linked_and_read_from_both_sides(): void
    {
        $b = $this->task($this->ecp, $this->pm);

        $this->link('blocks', $b)->assertSessionHasNoErrors();

        $source = $this->actingAsMember($this->tlA)->get('/tasks/ECP-1')->viewData('page')['props']['links'];
        $target = $this->actingAsMember($this->tlA)->get('/tasks/ECP-2')->viewData('page')['props']['links'];
        $this->assertSame(['blocks', 'ECP-2'], [$source[0]['wording'], $source[0]['task']['key']]);
        $this->assertSame(['is blocked by', 'ECP-1'], [$target[0]['wording'], $target[0]['task']['key']]);
        $this->assertSame(2, TaskLink::count() + 1, 'only one row is stored for the pair');
    }

    public function test_every_link_type_reads_both_ways(): void
    {
        $this->assertSame(['blocks', 'is blocked by'], TaskLink::WORDING['blocks']);
        $this->assertSame(['duplicates', 'is duplicated by'], TaskLink::WORDING['duplicates']);
        $this->assertSame(['clones', 'is cloned by'], TaskLink::WORDING['clones']);
        $this->assertSame(['relates to', 'relates to'], TaskLink::WORDING['relates_to']);
    }

    public function test_links_are_validated(): void
    {
        $b = $this->task($this->ecp, $this->pm);

        $this->link('hugs', $b)->assertSessionHasErrors('type');
        $this->link('blocks', $this->task)->assertSessionHasErrors('target'); // itself
        $this->link('blocks', $b)->assertSessionHasNoErrors();
        $this->link('blocks', $b)->assertSessionHasErrors('target'); // twice
        $this->link('relates_to', $b)->assertSessionHasNoErrors(); // another type is fine
        $this->link('relates_to', $this->task, null, 'ECP-2')->assertSessionHasErrors('target'); // "relates to" is symmetric
        $this->assertSame(2, TaskLink::count());
    }

    public function test_a_task_can_only_be_linked_to_tasks_the_person_can_see(): void
    {
        $hidden = $this->task($this->oth, $this->tlB); // TL-A cannot see OTH tasks

        $this->link('relates_to', $hidden)->assertNotFound();
        $this->assertSame(0, TaskLink::count());

        // The PM sees both worlds? No: the PM is on ECP only. The admin sees everything and may link across projects.
        $this->link('relates_to', $hidden, $this->admin)->assertSessionHasNoErrors();
    }

    public function test_linked_tasks_the_viewer_cannot_see_are_left_out_of_the_list(): void
    {
        $hidden = $this->task($this->oth, $this->tlB);
        $this->link('relates_to', $hidden, $this->admin);

        $seen = $this->actingAsMember($this->tlA)->get('/tasks/ECP-1')->viewData('page')['props']['links'];

        $this->assertSame([], $seen);
        $this->assertCount(1, $this->actingAsMember($this->admin)->get('/tasks/ECP-1')->viewData('page')['props']['links']);
    }

    public function test_links_can_be_removed_from_either_end(): void
    {
        $b = $this->task($this->ecp, $this->pm);
        $this->link('blocks', $b);
        $l = TaskLink::sole();

        $this->actingAsMember($this->dev1)->delete("/links/{$l->id}")->assertForbidden(); // developers lack task.link
        $this->actingAsMember($this->pm)->delete("/links/{$l->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, TaskLink::count());
        $this->assertSame('unlinked', $b->activities()->first()->event);
    }

    public function test_linking_needs_the_permission(): void
    {
        $b = $this->task($this->ecp, $this->pm);

        $this->link('blocks', $b, $this->dev1)->assertForbidden();
    }

    public function test_the_task_search_finds_by_key_and_title_within_what_is_visible(): void
    {
        $payment = $this->task($this->ecp, $this->pm, ['title' => 'Payment gateway timeout']);
        $this->task($this->oth, $this->tlB, ['title' => 'Payment report']);

        $search = fn (string $q, ?Membership $by = null, string $extra = '') => collect($this->actingAsMember($by ?? $this->tlA)->getJson('/tasks/search?q='.urlencode($q).$extra)->assertOk()->json());

        $this->assertSame([$payment->id], $search('gateway')->pluck('id')->all());
        $this->assertSame([$payment->id], $search('ECP-2')->pluck('id')->all());
        $this->assertCount(2, $search('ECP-')); // both ECP tasks
        $this->assertSame($payment->id, $search('payment')->first()['id']); // OTH's "Payment report" is invisible to TL-A
        $this->assertCount(1, $search('payment'));
        $this->assertCount(2, $search('payment', $this->admin));
        $this->assertCount(1, $search('ECP-', null, '&exclude='.$payment->id));
        $this->assertCount(0, $search('', $this->client)); // the client sees none of these
    }

    // ---- watchers -------------------------------------------------------------------------

    public function test_anyone_who_can_see_a_task_can_watch_and_unwatch_it(): void
    {
        $this->actingAsMember($this->dev1)->post('/tasks/ECP-1/watch')->assertSessionHasNoErrors();
        $this->assertContains($this->dev1->id, $this->task->watchers()->pluck('memberships.id')->all());

        $this->actingAsMember($this->dev1)->post('/tasks/ECP-1/watch'); // twice is harmless
        $this->assertSame(1, $this->task->watchers()->where('memberships.id', $this->dev1->id)->count());

        $this->actingAsMember($this->dev1)->delete('/tasks/ECP-1/watch')->assertSessionHasNoErrors();
        $this->assertNotContains($this->dev1->id, $this->task->watchers()->pluck('memberships.id')->all());
    }

    public function test_you_cannot_watch_what_you_cannot_see(): void
    {
        $this->actingAsMember($this->dev3)->post('/tasks/ECP-1/watch')->assertForbidden();
        $this->actingAsMember($this->client)->post('/tasks/ECP-1/watch')->assertForbidden();
    }

    public function test_the_page_lists_watchers_to_the_team_but_not_to_clients(): void
    {
        $this->task->watchers()->attach([$this->dev1->id, $this->client->id]);
        $own = $this->task($this->ecp, $this->client);

        $team = $this->actingAsMember($this->tlA)->get('/tasks/ECP-1')->viewData('page')['props'];
        $this->assertCount(2, $team['watchers']);

        $external = $this->actingAsMember($this->client)->get('/tasks/ECP-2')->viewData('page')['props'];
        $this->assertSame([], $external['watchers']);
        $this->assertNotNull($own);
    }
}
