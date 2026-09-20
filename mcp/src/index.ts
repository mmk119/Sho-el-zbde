#!/usr/bin/env node
/**
 * MCP server for Sho el Zbde.
 *
 * A client of the app's HTTP API and nothing more: it uploads a local audio
 * file and reads back the digest. It holds no credentials - the Laravel app
 * holds the OpenAI key and does all the work.
 *
 * Transport is stdio, so it runs as a subprocess of the MCP client.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";

import { BASE_URL } from "./constants.js";
import { registerGetDigest } from "./tools/getDigest.js";
import { registerSubmitVoiceNote } from "./tools/submitVoiceNote.js";

const server = new McpServer({
  name: "sho-el-zbde-mcp-server",
  version: "1.0.0",
});

registerSubmitVoiceNote(server);
registerGetDigest(server);

async function main(): Promise<void> {
  const transport = new StdioServerTransport();
  await server.connect(transport);

  // stdout is the protocol channel. Anything not JSON-RPC must go to stderr.
  console.error(`sho-el-zbde-mcp-server ready, talking to ${BASE_URL}`);
}

main().catch((error: unknown) => {
  console.error("Failed to start:", error instanceof Error ? error.message : String(error));
  process.exit(1);
});
