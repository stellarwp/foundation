---
title: What Foundation Is
description: Understand when to use the Foundation aggregate package or one of its focused components.
sidebar:
  order: 1
---

Foundation is a Composer monorepo of reusable PHP components maintained for libraries and WordPress plugin ecosystems. It provides common application infrastructure without requiring every project to invent its own container, logging, locking, database, identifier, pipeline, shutdown, view, or command conventions.

Foundation is primarily developed for internal Nexcess projects. Its packages are publicly available and designed to remain reusable, but the needs of Nexcess applications will primarily drive changes, priorities, and the project roadmap.

Each component is published as a focused package from this monorepo. Applications can adopt one capability without taking dependencies on unrelated Foundation features.

## Choose packages by deployment boundary

Libraries and distributable WordPress plugins should generally use focused component packages. This keeps their production dependency set aligned with the infrastructure they actually ship.

Complete applications that centrally own their dependency graph can use the aggregate package when having every Foundation component available is more useful than minimizing installed code. The installation guide explains the production and developer dependency implications of each approach.

## Treat Foundation as application infrastructure

Foundation components provide infrastructure and extension points. Application-specific decisions still belong to the consuming project:

- Select and configure the components the application needs.
- Register application services through focused providers.
- Set a unique application prefix for shared WordPress resources.
- Keep authorization, business rules, and domain behavior in application code.

Foundation does not require an application to adopt every component at once.
