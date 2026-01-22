This is a Laravel project, using PHP 8.4 with modern syntax.

We'll use Postgres for our database, with Redis for our queues.

The platform will likely be hosted on Laravel Cloud.

The goal of this project is to build an online product aggregation system.

This system scrapes various online websites at high scale, finding product listings and then collecting information from those listings such as:
- Title
- Description
- Ingredients
- Price
- Weight & Quantity
- Any discounts or coupons
- All reviews of the product
- Images
- Any relevant other information

We'll want to keep a database of store-specific product listings, so we can keep track of the individual
products, their prices over time, reviews, etc.

As well as this, we want to create a centralised product database. This links store-level
product listings into a central listing.

The product aggregator will be specific to dog food & treats for now, expanding into other pet products eventually.

We're targeting most UK stores with websites for now - big stores such as Tesco, B&M, Asda, Just for pets, Pets at home, etc.

From a project structure perspective, I want to use Event sourcing, more specifically Spatie's laravel-event-sourcing package.
I'd like the scraper/crawler structure to consist of Crawlers, Tasks, Extractors, etc. Each Scraper has a set of Extractors, each extractor works as a generator
yielding specific DTOs, these DTOs can be in the form of Product related data (such as a Review, Ingredients, etc).

This should be built as a highly scalable queued system, each job or task we should ensure is fully tracked in the event sourcing system.

For the web scraper, I want to design an adapter type system which allows us to plug-in different ways of "hitting a url" and receiving an HTML response for us to process.
Ideally for the initial version we'll use a hosted scraper API which is faster for us to get started. We need to ensure regardless of the type of site, we get the correct HTML body.

For anti-bot measures we'll use a combination of random real user agents and residential proxies. We'll stick to low requests per second per site, to ensure
we're not hammering any particular sites. Products should ideally be re-scraped once per day to run price & stock updates.

Matching products should use a combination of efforts, this should match based on product names, brands, weight/quantity, etc.
