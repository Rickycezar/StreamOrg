<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The light formatting of notification texts: what it makes, and what it never lets through. */
final class NoteFormatTest extends TestCase
{
    public function testInlineMarks(): void
    {
        $this->assertSame(
            '<p><strong>New</strong> and <em>soon</em>, <s>old</s> <code>a&lt;b</code></p>',
            NoteFormat::html('**New** and *soon*, ~~old~~ `a<b`')
        );
    }

    public function testBlocks(): void
    {
        $html = NoteFormat::html("## What changed\nFirst line\nsecond line\n\n- one\n- **two**\n\n1. a\n2. b\n\n> Heads up");

        $this->assertSame(
            '<h4>What changed</h4><p>First line<br>second line</p><ul><li>one</li><li><strong>two</strong></li></ul>'
            . '<ol><li>a</li><li>b</li></ol><blockquote>Heads up</blockquote>',
            $html
        );
    }

    public function testLinks(): void
    {
        $this->assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">the <strong>site</strong></a>',
            NoteFormat::html('See [the **site**](https://example.com/a?b=1&c=2).'));
        $this->assertStringContainsString('>https://twitch.tv/x</a>.', NoteFormat::html('Go to https://twitch.tv/x.'));
        $this->assertStringContainsString('href="' . url('/content') . '">here</a>', NoteFormat::html('[here](/content)'));
    }

    public function testNothingUnsafeGetsThrough(): void
    {
        $html = NoteFormat::html('<script>alert(1)</script> [x](javascript:alert(1)) [y](//evil.test) <img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringNotContainsString('href="//', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEscapedWordsStayLiteral(): void
    {
        $name = '*Star* [Team]_x';

        $this->assertSame('<p>Hi *Star* [Team]_x!</p>', NoteFormat::html('Hi ' . NoteFormat::escape($name) . '!'));
        $this->assertSame('Hi *Star* [Team]_x!', NoteFormat::plain('Hi ' . NoteFormat::escape($name) . '!'));
    }

    public function testPlainDropsTheMarks(): void
    {
        $this->assertSame('Title · one · **two** · see docs · quote', NoteFormat::plain("## Title\n- one\n- \\*\\*two\\*\\*\n\nsee [docs](https://x.y)\n> quote"));
        $this->assertSame('bold and italic', NoteFormat::plain('**bold** and *italic*'));
    }
}
