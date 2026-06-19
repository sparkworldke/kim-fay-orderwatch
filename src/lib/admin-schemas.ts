import { z } from "zod";

export const aiKeySchema = z.object({
  provider: z.enum(["openai", "anthropic"]),
  key: z.string().min(20, "API key must be at least 20 characters."),
});

export const acumaticaSchema = z.object({
  base_url: z.string().url("Enter a valid Acumatica base URL."),
  endpoint: z.string().min(1, "Endpoint is required."),
  version: z.string().min(1, "Version is required."),
  tenant: z.string().min(1, "Tenant is required."),
  username: z.string().min(1, "Username is required."),
  token_url: z.string().url("Enter a valid token URL."),
  password: z.string().optional(),
  client_id: z.string().optional(),
  client_secret: z.string().optional(),
});

export type AiKeyInput = z.infer<typeof aiKeySchema>;
export type AcumaticaInput = z.infer<typeof acumaticaSchema>;
