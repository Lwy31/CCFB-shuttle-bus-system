using HelloWebApp;
using Xunit;

namespace HelloWebApp.Tests;

public class DeploymentInfoTests
{
    [Fact]
    public void Load_ReturnsDefaults_WhenFileDoesNotExist()
    {
        var (message, id) = DeploymentInfo.Load("/no/such/file.json");

        Assert.Equal("Hello, World!", message);
        Assert.Equal("local-dev", id);
    }

    [Fact]
    public void Load_ReturnsValuesFromFile_WhenPresentAndValid()
    {
        var tempFile = Path.GetTempFileName();
        try
        {
            File.WriteAllText(tempFile, """{"websiteMessage": "Hi there", "deploymentId": "abc1234"}""");

            var (message, id) = DeploymentInfo.Load(tempFile);

            Assert.Equal("Hi there", message);
            Assert.Equal("abc1234", id);
        }
        finally
        {
            File.Delete(tempFile);
        }
    }

    [Fact]
    public void Load_FallsBackToDefaults_WhenFileIsMalformed()
    {
        var tempFile = Path.GetTempFileName();
        try
        {
            File.WriteAllText(tempFile, "{ this is not valid json");

            var (message, id) = DeploymentInfo.Load(tempFile);

            Assert.Equal("Hello, World!", message);
            Assert.Equal("local-dev", id);
        }
        finally
        {
            File.Delete(tempFile);
        }
    }
}
