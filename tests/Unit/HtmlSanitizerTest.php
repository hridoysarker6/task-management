<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_formatting_is_kept(): void
    {
        $html = '<h2>Title</h2><p>Some <strong>bold</strong>, <em>italic</em> and <code>code</code>.</p><ul><li>one</li><li>two</li></ul><blockquote>quote</blockquote>';

        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    public function test_empty_input_becomes_null(): void
    {
        foreach ([null, '', '   ', '<p></p>', '<p> </p>', '<p><br></p>'] as $empty) {
            $this->assertNull(HtmlSanitizer::clean($empty), var_export($empty, true));
        }
    }

    #[DataProvider('attacks')]
    public function test_attacks_are_neutralised(string $input, array $mustNotContain): void
    {
        $out = (string) HtmlSanitizer::clean($input);

        foreach ($mustNotContain as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $out, "input: {$input}");
        }
    }

    public static function attacks(): array
    {
        return [
            'script tag' => ['<p>hi</p><script>alert(1)</script>', ['<script', 'alert(1)']],
            'event handler' => ['<p onclick="steal()">x</p>', ['onclick', 'steal']],
            'onerror image' => ['<img src=x onerror=alert(1)>', ['<img', 'onerror', 'alert']],
            'javascript link' => ['<a href="javascript:alert(1)">x</a>', ['javascript:', '<a']],
            'mixed case javascript link' => ['<a href="JaVaScRiPt:alert(1)">x</a>', ['javascript:']],
            'data link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', ['data:']],
            'vbscript link' => ['<a href="vbscript:msgbox(1)">x</a>', ['vbscript']],
            'iframe' => ['<iframe src="https://evil.test"></iframe>text', ['<iframe', 'evil.test']],
            'svg' => ['<svg onload=alert(1)><circle/></svg>', ['<svg', 'onload', 'alert']],
            'style tag' => ['<style>body{display:none}</style><p>x</p>', ['<style', 'display:none']],
            'style attribute' => ['<p style="background:url(javascript:alert(1))">x</p>', ['style=', 'javascript']],
            'form' => ['<form action="https://evil.test"><input name=p></form>', ['<form', '<input', 'evil.test']],
            'object and embed' => ['<object data="x.swf"></object><embed src="x.swf">', ['<object', '<embed']],
            'nested' => ['<div><p>ok</p><script>bad()</script></div>', ['<script', 'bad()']],
            'html comment' => ['<p>a</p><!-- <script>x()</script> -->', ['<!--', 'x()']],
            'unclosed' => ['<p>one<script>two', ['<script']],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">x', ['<meta', 'evil.test']],
            'base tag' => ['<base href="https://evil.test/">x', ['<base', 'evil.test']],
        ];
    }

    public function test_safe_links_keep_their_address_and_gain_safe_attributes(): void
    {
        $out = HtmlSanitizer::clean('<a href="https://ok.test/path?q=1" target="_self" onclick="x()">go</a> <a href="mailto:a@b.test">mail</a>');

        $this->assertStringContainsString('href="https://ok.test/path?q=1"', $out);
        $this->assertStringContainsString('href="mailto:a@b.test"', $out);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('_self', $out);
    }

    public function test_unknown_tags_lose_their_wrapper_but_keep_their_text(): void
    {
        $this->assertSame('<p>hello <strong>world</strong></p>', HtmlSanitizer::clean('<div><span class="x"><p>hello <strong>world</strong></p></span></div>'));
    }

    public function test_unicode_and_entities_survive(): void
    {
        $out = HtmlSanitizer::clean('<p>বাংলা &amp; café &lt;b&gt;</p>');

        $this->assertStringContainsString('বাংলা', $out);
        $this->assertStringContainsString('café', $out);
        $this->assertStringContainsString('&lt;b&gt;', $out);
    }

    public function test_the_result_is_stable_when_cleaned_twice(): void
    {
        $once = HtmlSanitizer::clean('<p>a <a href="https://ok.test">b</a> <script>x</script></p><ul><li>c</li></ul>');

        $this->assertSame($once, HtmlSanitizer::clean($once));
    }

    public function test_text_extraction_for_previews(): void
    {
        $this->assertSame('Hello world and more', HtmlSanitizer::text('<p>Hello <strong>world</strong></p><ul><li>and more</li></ul>'));
        $this->assertSame('', HtmlSanitizer::text(null));
    }
}
