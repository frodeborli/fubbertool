# Fubber

**Multi-language codebase inspection tool for developers and AI coding assistants**

Fubber is a command-line tool for browsing and analyzing code using reflection and static analysis. Currently supports PHP with plans for Python, JavaScript, and more.

## Vision

Fubber aims to be the Swiss Army knife for codebase exploration:

- **Multi-language**: Currently PHP, with Python, JavaScript, TypeScript, and more planned
- **Fast exploration**: Query your codebase without opening an IDE
- **AI-friendly**: Perfect for LLM coding assistants to understand codebases
- **Framework-agnostic**: Works with any PHP project

## Current Features (PHP)

- **Entity lookup**: Explore namespaces, classes, interfaces, traits, enums, methods, properties, constants, and functions
- **Pattern search**: Find entities by name or signature using substring or regex patterns
- **Relationship queries**: Find implementations, subclasses, trait users
- **Type queries**: Find methods accepting or returning specific types
- **Attribute queries**: Discover PHP 8 attribute usage across your codebase

## Installation

### Via Composer (recommended)

```bash
composer global require fubber/fubber
```

Make sure your Composer global bin directory is in your PATH:
```bash
export PATH="$PATH:$HOME/.config/composer/vendor/bin"
```

### From source

```bash
git clone https://github.com/frodeborli/fubber.git ~/fubbertool
cd ~/fubbertool
composer install
ln -s ~/fubbertool/bin/fubber ~/.local/bin/fubber
```

## Usage

Navigate to any PHP project directory and run `fubber`:

```bash
cd /path/to/your/project
fubber --help
```

### Basic Examples

**Explore a namespace:**
```bash
fubber App\\Models
```

**View class documentation:**
```bash
fubber "App\\Models\\User"
```

**View method details:**
```bash
fubber "App\\Models\\User::save"
```

**Search for entities:**
```bash
fubber search Router              # Find anything with "Router"
fubber search -i cache            # Case-insensitive search
fubber search '\$request'         # Find methods with $request parameter
```

### Relationship Queries

**Find all implementations of an interface:**
```bash
fubber implements CacheInterface
```

**Find all subclasses:**
```bash
fubber extends Repository
```

**Find classes using a trait:**
```bash
fubber uses LoggableTrait
```

### Type-based Queries

**Find methods accepting a specific type:**
```bash
fubber accepts Request
```

**Find methods returning a specific type:**
```bash
fubber returns Response
```

### Attribute Queries

**Find all uses of a PHP 8 attribute:**
```bash
fubber attributes Route
fubber attributes Column
```

## How It Works

Fubber uses PHP's built-in Reflection API to analyze your codebase:

1. Discovers classes via Composer's autoloader metadata
2. Uses reflection to extract signatures, types, and documentation
3. Presents results in a clean, scannable format

No external dependencies required - if you can run PHP, you can run Fubber.

## Future Plans

- **Python support**: `fubber-python` using Python's inspect module
- **JavaScript/TypeScript**: `fubber-js` using TypeScript compiler API
- **Full-text search**: SQLite FTS5 indexing of docblocks
- **Cross-language queries**: Find similar patterns across languages
- **C binary**: Fast dispatcher to delegate to language-specific implementations

## Why Fubber?

**For developers:**
- Faster than opening an IDE for quick lookups
- Great for exploring unfamiliar codebases
- Works over SSH where GUIs aren't available

**For AI coding assistants:**
- Efficient way to understand project structure
- More accurate than grep/text search
- Type-aware queries reduce hallucination

## Contributing

Contributions welcome! This is an early-stage project with lots of room for improvement.

## License

MIT License - see LICENSE file for details

## Author

Created by Frode Borli

---

*Fubber: The name suggests a tool - like a Swiss Army knife for code inspection.*
