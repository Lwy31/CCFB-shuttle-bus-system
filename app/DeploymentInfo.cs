using System.Text.Json;

namespace HelloWebApp;

/// <summary>
/// Reads the build-time metadata (deployment.json) that ships bundled
/// inside the published, zipped artifact. Separated from Program.cs
/// specifically so it can be unit tested without spinning up the whole
/// web host — see HelloWebApp.Tests/DeploymentInfoTests.cs.
/// </summary>
public static class DeploymentInfo
{
    public static (string WebsiteMessage, string DeploymentId) Load(string filePath)
    {
        var websiteMessage = "Hello, World!";
        var deploymentId = "local-dev";

        if (!File.Exists(filePath))
        {
            return (websiteMessage, deploymentId);
        }

        try
        {
            var json = File.ReadAllText(filePath);
            var parsed = JsonSerializer.Deserialize<Dictionary<string, string>>(json);
            if (parsed != null)
            {
                websiteMessage = parsed.GetValueOrDefault("websiteMessage", websiteMessage);
                deploymentId = parsed.GetValueOrDefault("deploymentId", deploymentId);
            }
        }
        catch (JsonException)
        {
            // Malformed deployment.json falls back to defaults rather than
            // crashing the app on startup.
        }

        return (websiteMessage, deploymentId);
    }
}
