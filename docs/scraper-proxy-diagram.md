# Scraper & Proxy Architecture Diagram

## Component Relationships

```
┌─────────────────────────────────────────────────────────────────┐
│                      HTTP Adapter Layer                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌───────────────────────────────────────────────────────────┐ │
│  │              GuzzleHttpAdapter                            │ │
│  │              (Production-Ready)                           │ │
│  ├───────────────────────────────────────────────────────────┤ │
│  │ - HttpAdapterInterface                                    │ │
│  │ - SupportsProxyInterface                                  │ │
│  │ - User Agent Rotation (32 realistic browsers)            │ │
│  │ - Anti-bot Headers (Sec-Fetch-*, Accept-*, etc.)         │ │
│  │ - Automatic Proxy Rotation (configurable)                │ │
│  │ - Comprehensive Logging (masked credentials)             │ │
│  │ - Configurable: rotateUserAgent, rotateProxy             │ │
│  └────────────────────────┬──────────────────────────────────┘ │
│                           │                                    │
└───────────────────────────┼────────────────────────────────────┘
                            │
                            │ withProxy()
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Proxy Providers Layer                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────┐ │
│  │ BrightData       │  │  ProxyManager    │  │ NullProxy    │ │
│  │ ProxyAdapter     │  │                  │  │  Adapter     │ │
│  ├──────────────────┤  ├──────────────────┤  ├──────────────┤ │
│  │ - ProxyAdapter   │  │ - ProxyAdapter   │  │ - ProxyAdapter│ │
│  │                  │  │ - Manages pool   │  │ - Returns    │ │
│  │ - Residential    │  │ - Round-robin    │  │   null       │ │
│  │ - Session rotate │  │ - Failover       │  │              │ │
│  └──────────────────┘  └────────┬─────────┘  └──────────────┘ │
│                                 │                              │
│                                 │ Contains                     │
│                                 ▼                              │
│                    ┌──────────────────────┐                   │
│                    │  Other Proxy         │                   │
│                    │  Providers:          │                   │
│                    │  - Oxylabs           │                   │
│                    │  - SmartProxy        │                   │
│                    │  - Custom providers  │                   │
│                    └──────────────────────┘                   │
└─────────────────────────────────────────────────────────────────┘
```

## Interface Hierarchy

```
┌──────────────────────────┐
│  HttpAdapterInterface    │
│  (Required for all)      │
├──────────────────────────┤
│ + fetchHtml()            │
│ + getLastStatusCode()    │
│ + getLastHeaders()       │
└────────────┬─────────────┘
             │
             │ implements
             │
     ┌───────┴────────┐
     │                │
     ▼                ▼
Adapters         Adapters with
without          proxy support
proxies          │
                 │ also implements
                 ▼
         ┌──────────────────────────┐
         │  SupportsProxyInterface  │
         ├──────────────────────────┤
         │ + withProxy()            │
         │ + getProxyAdapter()      │
         └──────────────────────────┘


┌──────────────────────────┐
│  ProxyAdapterInterface   │
│  (Proxy providers)       │
├──────────────────────────┤
│ + getProxyUrl()          │
│ + getProxyConfig()       │
│ + isAvailable()          │
│ + rotate()               │
└──────────────────────────┘
```

## Usage Flow

### With External Proxy (GuzzleHttpAdapter)

```
User Request
    │
    ▼
┌────────────────────┐
│ Create HTTP        │
│ Adapter            │
│ new GuzzleHttp     │
│ Adapter(           │
│   rotateUA: true,  │
│   rotateProxy: true│
│ )                  │
└────────┬───────────┘
         │
         ▼
┌────────────────────┐
│ Create Proxy       │
│ Provider           │
│ (BrightData/etc)   │
└────────┬───────────┘
         │
         ▼
┌────────────────────┐
│ adapter.withProxy  │
│ (proxyProvider)    │
└────────┬───────────┘
         │
         ▼
┌────────────────────┐
│ adapter.fetchHtml  │
│ • Rotates UA       │
│ • Rotates proxy    │
│ • Anti-bot headers │
│ • Logs request     │
└────────────────────┘
```

### Without Proxy (Direct Connection)

```
User Request
    │
    ▼
┌────────────────────┐
│ Create HTTP        │
│ Adapter            │
│ new GuzzleHttp     │
│ Adapter()          │
│ (no proxy config)  │
└────────┬───────────┘
         │
         ▼
┌────────────────────┐
│ adapter.fetchHtml  │
│ • Still uses UA    │
│   rotation         │
│ • Anti-bot headers │
│ • No proxy         │
└────────────────────┘
```

## Key Design Decisions

1. **Interface Segregation**:
   - `HttpAdapterInterface` for basic HTTP operations
   - `SupportsProxyInterface` only for adapters that need external proxies
   - `ProxyAdapterInterface` for proxy providers

2. **Fluent API**:
   - `withProxy()` returns `static` for method chaining
   - Easy to configure adapters

3. **Null Object Pattern**:
   - `NullProxyAdapter` for explicit "no proxy" configuration
   - Avoids null checks in client code

4. **Composition over Inheritance**:
   - Proxy providers are composed into adapters
   - Not hardcoded in adapter constructors

5. **Open/Closed Principle**:
   - Easy to add new adapters without modifying existing code
   - Easy to add new proxy providers without modifying adapters
