using System;
using HelloWebApp;
using Xunit;

namespace HelloWebApp.Tests;

public class MessageBoardTests
{
    [Fact]
    public void Add_AssignsIncrementingIds()
    {
        var board = new MessageBoard();

        var first = board.Add("Ada", "hello");
        var second = board.Add("Alan", "world");

        Assert.Equal(1, first.Id);
        Assert.Equal(2, second.Id);
        Assert.Equal(2, board.Count);
    }

    [Fact]
    public void Add_DefaultsAuthorToAnonymous_WhenBlank()
    {
        var board = new MessageBoard();

        var message = board.Add("   ", "no name given");

        Assert.Equal("Anonymous", message.Author);
    }

    [Fact]
    public void Add_TrimsAndKeepsText()
    {
        var board = new MessageBoard();

        var message = board.Add("  Grace  ", "  spaced out  ");

        Assert.Equal("Grace", message.Author);
        Assert.Equal("spaced out", message.Text);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("    ")]
    public void Add_ThrowsWhenTextMissing(string? text)
    {
        var board = new MessageBoard();

        Assert.Throws<ArgumentException>(() => board.Add("Someone", text));
    }

    [Fact]
    public void Add_TruncatesTextToMaxLength()
    {
        var board = new MessageBoard();

        var message = board.Add("Chatterbox", new string('x', MessageBoard.MaxLength + 50));

        Assert.Equal(MessageBoard.MaxLength, message.Text.Length);
    }

    [Fact]
    public void All_ReturnsNewestFirst()
    {
        var board = new MessageBoard();
        board.Add("A", "first");
        board.Add("B", "second");

        var all = board.All();

        Assert.Equal("second", all[0].Text);
        Assert.Equal("first", all[1].Text);
    }
}
