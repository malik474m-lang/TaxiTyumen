using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace TaxiService.Infrastructure.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddOperatorPayment : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "operator_profiles",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    UserId = table.Column<Guid>(type: "uuid", nullable: false),
                    Scheme = table.Column<int>(type: "integer", nullable: false),
                    RatePerOrder = table.Column<decimal>(type: "numeric(10,2)", precision: 10, scale: 2, nullable: false),
                    RatePerHour = table.Column<decimal>(type: "numeric(10,2)", precision: 10, scale: 2, nullable: false),
                    RatePerDay = table.Column<decimal>(type: "numeric(10,2)", precision: 10, scale: 2, nullable: false),
                    FixedMonthly = table.Column<decimal>(type: "numeric(12,2)", precision: 12, scale: 2, nullable: false),
                    TotalOrdersAccepted = table.Column<int>(type: "integer", nullable: false),
                    TotalEarnings = table.Column<decimal>(type: "numeric(12,2)", precision: 12, scale: 2, nullable: false),
                    CreatedAt = table.Column<DateTime>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_operator_profiles", x => x.Id);
                    table.ForeignKey(
                        name: "FK_operator_profiles_users_UserId",
                        column: x => x.UserId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "operator_shifts",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    OperatorId = table.Column<Guid>(type: "uuid", nullable: false),
                    ProfileId = table.Column<Guid>(type: "uuid", nullable: true),
                    StartedAt = table.Column<DateTime>(type: "timestamp with time zone", nullable: false),
                    EndedAt = table.Column<DateTime>(type: "timestamp with time zone", nullable: true),
                    HoursWorked = table.Column<double>(type: "double precision", nullable: false),
                    OrdersAccepted = table.Column<int>(type: "integer", nullable: false),
                    Earned = table.Column<decimal>(type: "numeric(10,2)", precision: 10, scale: 2, nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_operator_shifts", x => x.Id);
                    table.ForeignKey(
                        name: "FK_operator_shifts_operator_profiles_ProfileId",
                        column: x => x.ProfileId,
                        principalTable: "operator_profiles",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_operator_shifts_users_OperatorId",
                        column: x => x.OperatorId,
                        principalTable: "users",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateIndex(
                name: "IX_operator_profiles_UserId",
                table: "operator_profiles",
                column: "UserId",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_operator_shifts_OperatorId",
                table: "operator_shifts",
                column: "OperatorId");

            migrationBuilder.CreateIndex(
                name: "IX_operator_shifts_ProfileId",
                table: "operator_shifts",
                column: "ProfileId");

            migrationBuilder.CreateIndex(
                name: "IX_operator_shifts_StartedAt",
                table: "operator_shifts",
                column: "StartedAt");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "operator_shifts");

            migrationBuilder.DropTable(
                name: "operator_profiles");
        }
    }
}
